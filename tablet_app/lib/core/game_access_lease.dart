import 'dart:math';

/// A conservative, local copy of the last game-access decision made by the
/// TablePlay server.
///
/// Network failures never grant access. They may only preserve an existing
/// approval until its last known server expiry. Authentication, pairing,
/// entitlement and missing-session responses revoke the approval immediately.
class GameAccessLease {
  GameAccessLease({DateTime Function()? now}) : _now = now ?? DateTime.now;

  final DateTime Function() _now;
  DateTime? _deadline;
  bool _approved = false;
  String _reason = 'timer';

  bool get unlocked {
    _expireIfNeeded();
    return _approved;
  }

  String get reason {
    _expireIfNeeded();
    return _reason;
  }

  int get remainingSeconds {
    _expireIfNeeded();
    if (!_approved || _deadline == null) return 0;

    final milliseconds = _deadline!.difference(_now()).inMilliseconds;
    return max(0, (milliseconds / 1000).ceil());
  }

  DateTime? get deadline => _deadline;

  /// Applies a successful `/games/access` (or snapshot) response.
  ///
  /// [requestStartedAt] should be supplied for live requests. Anchoring the
  /// relative server duration to the beginning of the request ensures network
  /// latency can only shorten the local lease, never extend it.
  void applyServerState(
    Map<String, dynamic> state, {
    DateTime? requestStartedAt,
  }) {
    if (state['unlocked'] != true) {
      revoke(reason: _normaliseReason(state['reason']));
      return;
    }

    final observedAt = _now();
    final anchor = requestStartedAt ?? observedAt;
    final candidates = <DateTime>[];

    final remaining = state['remaining_seconds'];
    if (remaining is num && remaining.isFinite) {
      candidates.add(
        anchor.add(
          Duration(milliseconds: max(0, remaining * 1000).floor()),
        ),
      );
    }

    final expiresAt = _parseDate(state['expires_at']);
    final serverTime = _parseDate(state['server_time']);
    if (expiresAt != null) {
      // The absolute timestamp is an additional safety bound on devices whose
      // clock is correct. The server-relative bound below handles clock skew.
      candidates.add(expiresAt.toLocal());
      if (serverTime != null) {
        final serverDuration = expiresAt.difference(serverTime);
        candidates.add(anchor.add(serverDuration));
      }
    }

    if (candidates.isEmpty) {
      // An unlocked response without a verifiable expiry must never unlock a
      // game. Current TablePlay servers always return the fields above.
      revoke(reason: 'access');
      return;
    }

    candidates.sort();
    final deadline = candidates.first;
    if (!deadline.isAfter(observedAt)) {
      revoke(reason: 'timer');
      return;
    }

    _deadline = deadline;
    _approved = true;
    _reason = '';
  }

  /// Returns true when [status] is an authoritative revocation response.
  bool revokeForHttpStatus(int status) {
    if (status != 401 && status != 403 && status != 404) return false;

    revoke(
      reason: switch (status) {
        403 => 'plan',
        404 => 'session',
        _ => 'access',
      },
    );
    return true;
  }

  void revoke({String reason = 'access'}) {
    _approved = false;
    _deadline = null;
    _reason = _normaliseReason(reason);
  }

  void refresh() => _expireIfNeeded();

  void _expireIfNeeded() {
    if (_approved && (_deadline == null || !_deadline!.isAfter(_now()))) {
      _approved = false;
      _deadline = null;
      _reason = 'timer';
    }
  }

  static DateTime? _parseDate(dynamic value) {
    if (value == null) return null;
    return DateTime.tryParse(value.toString());
  }

  static String _normaliseReason(dynamic value) {
    final reason = value?.toString().trim().toLowerCase() ?? '';
    return switch (reason) {
      'plan' || 'session' || 'access' || 'timer' => reason,
      _ => 'timer',
    };
  }
}
