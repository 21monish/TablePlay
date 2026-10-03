import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tableplay_tablet/core/device_store.dart';

void main() {
  test('customer pairing survives restart and clears only on reset', () async {
    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    final first = DeviceStore(preferences);

    await first.savePairing(
      baseUrl: 'http://10.0.0.2:8000/api/v1',
      uuid: first.uuid,
      token: 'persistent-device-token',
      tableCode: 'T01',
    );

    final restarted = DeviceStore(preferences);
    expect(restarted.isPaired, isTrue);
    expect(restarted.token, 'persistent-device-token');
    expect(restarted.tableCode, 'T01');

    await restarted.clear();
    expect(DeviceStore(preferences).isPaired, isFalse);
  });
}
