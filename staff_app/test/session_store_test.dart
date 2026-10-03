import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tableplay_staff/core/session_store.dart';

void main() {
  test(
    'staff login survives store recreation and clears only on logout',
    () async {
      SharedPreferences.setMockInitialValues({});
      final preferences = await SharedPreferences.getInstance();
      final first = SessionStore(preferences);

      await first.save(
        baseUrl: 'http://10.0.0.2:8000/api/v1',
        token: 'persistent-token',
        role: 'counter',
        name: 'Counter One',
        userId: 7,
      );

      final restarted = SessionStore(preferences);
      expect(restarted.signedIn, isTrue);
      expect(restarted.token, 'persistent-token');
      expect(restarted.role, 'counter');

      await restarted.clear();
      expect(SessionStore(preferences).signedIn, isFalse);
    },
  );
}
