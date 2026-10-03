import 'dart:io';
import 'dart:math';

import 'package:shared_preferences/shared_preferences.dart';

class SessionStore {
  SessionStore(this.preferences) {
    installationUuid = preferences.getString('installation_uuid') ?? _newUuid();
    if (!preferences.containsKey('installation_uuid')) {
      preferences.setString('installation_uuid', installationUuid);
    }
  }
  final SharedPreferences preferences;
  late final String installationUuid;
  String get baseUrl {
    final stored = preferences.getString('base_url')?.trim();
    if (stored != null && stored.isNotEmpty) return stored;
    return Platform.isWindows ? 'http://127.0.0.1:8000/api/v1' : '';
  }

  String? get token => preferences.getString('staff_token');
  String? get role => preferences.getString('staff_role');
  String? get name => preferences.getString('staff_name');
  int? get userId => preferences.getInt('staff_id');
  String? get serverName => preferences.getString('server_name');
  String? get lastConnectedAt => preferences.getString('last_connected_at');
  bool get signedIn => token != null && role != null;

  Future<void> save({
    required String baseUrl,
    required String token,
    required String role,
    required String name,
    required int userId,
  }) async {
    await preferences.setString('base_url', baseUrl);
    await preferences.setString('staff_token', token);
    await preferences.setString('staff_role', role);
    await preferences.setString('staff_name', name);
    await preferences.setInt('staff_id', userId);
  }

  Future<void> clear() async {
    for (final key in ['staff_token', 'staff_role', 'staff_name', 'staff_id']) {
      await preferences.remove(key);
    }
  }

  Future<void> saveServer(String baseUrl, {String? serverName}) async {
    await preferences.setString('base_url', baseUrl);
    if (serverName != null && serverName.trim().isNotEmpty) {
      await preferences.setString('server_name', serverName.trim());
    }
    await preferences.setString(
      'last_connected_at',
      DateTime.now().toUtc().toIso8601String(),
    );
  }

  Future<void> forgetServer() async {
    await preferences.remove('base_url');
    await preferences.remove('server_name');
    await preferences.remove('last_connected_at');
  }

  static String _newUuid() {
    final random = Random.secure();
    final bytes = List<int>.generate(16, (_) => random.nextInt(256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    final hex = bytes
        .map((value) => value.toRadixString(16).padLeft(2, '0'))
        .join();
    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-${hex.substring(16, 20)}-${hex.substring(20)}';
  }
}
