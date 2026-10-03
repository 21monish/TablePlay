import 'dart:math';
import 'package:shared_preferences/shared_preferences.dart';

class DeviceStore {
  DeviceStore(this.preferences) {
    uuid = preferences.getString('device_uuid') ?? _newUuid();
    if (!preferences.containsKey('device_uuid')) {
      preferences.setString('device_uuid', uuid);
    }
  }
  final SharedPreferences preferences;
  String get baseUrl => preferences.getString('base_url')?.trim() ?? '';
  late final String uuid;
  String? get token => preferences.getString('device_token');
  String? get tableCode => preferences.getString('table_code');
  bool get isPaired => token != null && tableCode != null;
  Future<void> savePairing(
      {required String baseUrl,
      required String uuid,
      required String token,
      required String tableCode}) async {
    await preferences.setString('base_url', baseUrl);
    await preferences.setString('device_uuid', uuid);
    await preferences.setString('device_token', token);
    await preferences.setString('table_code', tableCode);
  }

  Future<void> clear() async {
    for (final key in ['device_token', 'table_code']) {
      await preferences.remove(key);
    }
  }

  static String _newUuid() {
    final r = Random.secure(),
        b = List<int>.generate(16, (_) => r.nextInt(256));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    final h = b.map((v) => v.toRadixString(16).padLeft(2, '0')).join();
    return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
  }
}
