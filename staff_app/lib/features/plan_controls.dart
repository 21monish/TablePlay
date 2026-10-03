class PairingControlState {
  const PairingControlState({
    required this.eligibleDevices,
    required this.eligibleTables,
    required this.planAllowed,
    required this.quotaAvailable,
    required this.message,
  });

  final List<Map<String, dynamic>> eligibleDevices;
  final List<Map<String, dynamic>> eligibleTables;
  final bool planAllowed;
  final bool quotaAvailable;
  final String message;

  bool get canPair =>
      planAllowed &&
      quotaAvailable &&
      eligibleDevices.isNotEmpty &&
      eligibleTables.isNotEmpty;

  bool get isPlanBlocked => !planAllowed || !quotaAvailable;

  factory PairingControlState.evaluate({
    required Map<String, dynamic> entitlements,
    required List<dynamic> devices,
    required List<dynamic> tables,
  }) {
    final eligibleDevices = devices
        .whereType<Map>()
        .map(Map<String, dynamic>.from)
        .where(
          (device) =>
              device['device_type'] == 'tablet' &&
              device['is_active'] == true &&
              _pairings(device).isEmpty,
        )
        .toList();
    final eligibleTables = tables
        .whereType<Map>()
        .map(Map<String, dynamic>.from)
        .where(
          (table) =>
              table['is_active'] == true &&
              table['status'] != 'disabled' &&
              _pairings(table).isEmpty,
        )
        .toList();

    if (entitlements.isEmpty) {
      return PairingControlState(
        eligibleDevices: eligibleDevices,
        eligibleTables: eligibleTables,
        planAllowed: false,
        quotaAvailable: false,
        message: 'Checking the restaurant licence before pairing.',
      );
    }

    if (entitlements['licensed'] != true) {
      return PairingControlState(
        eligibleDevices: eligibleDevices,
        eligibleTables: eligibleTables,
        planAllowed: false,
        quotaAvailable: false,
        message: 'Activate or renew TablePlay before pairing another tablet.',
      );
    }

    if (!planFeatureAllowed(entitlements, 'customer_app')) {
      return PairingControlState(
        eligibleDevices: eligibleDevices,
        eligibleTables: eligibleTables,
        planAllowed: false,
        quotaAvailable: false,
        message:
            'The current plan does not include the Customer Table application.',
      );
    }

    final limits = entitlements['limits'];
    final usage = entitlements['usage'];
    final rawLimit = limits is Map ? limits['paired_tables'] : null;
    final used = usage is Map && usage['paired_tables'] is num
        ? (usage['paired_tables'] as num).toInt()
        : 0;
    final limit = rawLimit is num ? rawLimit.toInt() : null;

    if (limit != null && used >= limit) {
      final plan = entitlements['plan'];
      final planName = plan is Map ? '${plan['name'] ?? 'Current'}' : 'Current';
      return PairingControlState(
        eligibleDevices: eligibleDevices,
        eligibleTables: eligibleTables,
        planAllowed: true,
        quotaAvailable: false,
        message:
            '$planName allows $limit paired tablet${limit == 1 ? '' : 's'}. Unpair one or upgrade the plan.',
      );
    }

    final message = eligibleDevices.isEmpty
        ? 'No active, unpaired Customer tablet is available.'
        : eligibleTables.isEmpty
        ? 'No active, unpaired dining table is available.'
        : 'A Customer tablet and dining table are ready to pair.';

    return PairingControlState(
      eligibleDevices: eligibleDevices,
      eligibleTables: eligibleTables,
      planAllowed: true,
      quotaAvailable: true,
      message: message,
    );
  }

  static List<dynamic> _pairings(Map<String, dynamic> value) {
    final pairings = value['pairings'];
    return pairings is List ? pairings : const [];
  }
}

bool planFeatureAllowed(
  Map<String, dynamic> entitlements,
  String feature, {
  bool whileUnknown = true,
}) {
  if (entitlements.isEmpty) return whileUnknown;
  final features = entitlements['features'];
  return entitlements['licensed'] == true &&
      features is Map &&
      features[feature] == true;
}
