import 'package:flutter_test/flutter_test.dart';
import 'package:tableplay_staff/features/plan_controls.dart';

void main() {
  final usableDevice = {
    'id': 1,
    'device_type': 'tablet',
    'is_active': true,
    'pairings': <dynamic>[],
  };
  final usableTable = {
    'id': 1,
    'status': 'available',
    'is_active': true,
    'pairings': <dynamic>[],
  };

  Map<String, dynamic> plan({
    bool licensed = true,
    bool customerApp = true,
    bool waiterOrdering = true,
    int? limit = 5,
    int used = 0,
  }) => {
    'licensed': licensed,
    'plan': {'name': 'Best'},
    'features': {
      'customer_app': customerApp,
      'waiter_ordering': waiterOrdering,
    },
    'limits': {'paired_tables': limit},
    'usage': {'paired_tables': used},
  };

  test(
    'pairing candidates exclude paired, inactive, disabled and staff rows',
    () {
      final state = PairingControlState.evaluate(
        entitlements: plan(),
        devices: [
          usableDevice,
          {...usableDevice, 'id': 2, 'is_active': false},
          {...usableDevice, 'id': 3, 'device_type': 'counter'},
          {
            ...usableDevice,
            'id': 4,
            'pairings': [
              {'id': 40},
            ],
          },
        ],
        tables: [
          usableTable,
          {...usableTable, 'id': 2, 'is_active': false},
          {...usableTable, 'id': 3, 'status': 'disabled'},
          {
            ...usableTable,
            'id': 4,
            'pairings': [
              {'id': 41},
            ],
          },
        ],
      );

      expect(state.canPair, isTrue);
      expect(state.eligibleDevices.map((row) => row['id']), [1]);
      expect(state.eligibleTables.map((row) => row['id']), [1]);
    },
  );

  test('pairing is locked when customer app is absent or quota is full', () {
    final unavailable = PairingControlState.evaluate(
      entitlements: plan(customerApp: false),
      devices: [usableDevice],
      tables: [usableTable],
    );
    expect(unavailable.canPair, isFalse);
    expect(unavailable.isPlanBlocked, isTrue);
    expect(unavailable.message, contains('does not include'));

    final full = PairingControlState.evaluate(
      entitlements: plan(limit: 5, used: 5),
      devices: [usableDevice],
      tables: [usableTable],
    );
    expect(full.canPair, isFalse);
    expect(full.quotaAvailable, isFalse);
    expect(full.message, contains('5 paired tablets'));
  });

  test(
    'unlimited plan and waiter ordering feature are represented accurately',
    () {
      final unlimited = PairingControlState.evaluate(
        entitlements: plan(limit: null, used: 250),
        devices: [usableDevice],
        tables: [usableTable],
      );
      expect(unlimited.canPair, isTrue);
      expect(
        planFeatureAllowed(plan(waiterOrdering: false), 'waiter_ordering'),
        isFalse,
      );
      expect(
        planFeatureAllowed(plan(waiterOrdering: true), 'waiter_ordering'),
        isTrue,
      );
    },
  );
}
