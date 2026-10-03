import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tableplay_tablet/core/device_store.dart';
import 'package:tableplay_tablet/features/setup_screen_v2.dart';
import 'package:tableplay_tablet/theme/tableplay_theme.dart';

void main() {
  testWidgets('unpaired tablet requires secure QR onboarding', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final store = DeviceStore(await SharedPreferences.getInstance());
    await tester.pumpWidget(MaterialApp(
        theme: tablePlayTheme(),
        home: SetupScreenV2(store: store, onPaired: () {})));
    expect(find.text('Set up this table'), findsOneWidget);
    expect(find.text('Scan and connect this table'), findsOneWidget);
    expect(find.text('Secure one-time pairing'), findsOneWidget);
    expect(find.text('Connection checklist'), findsOneWidget);
    expect(find.text('Register and pair'), findsNothing);
    expect(find.text('Server API URL'), findsNothing);
    expect(find.text('Table code'), findsNothing);
  });
}
