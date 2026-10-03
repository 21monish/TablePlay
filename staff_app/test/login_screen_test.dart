import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tableplay_staff/core/session_store.dart';
import 'package:tableplay_staff/features/login_screen.dart';
import 'package:tableplay_staff/theme/tableplay_theme.dart';

void main() {
  testWidgets('staff login presents role-based sign in', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final store = SessionStore(await SharedPreferences.getInstance());
    await tester.pumpWidget(
      MaterialApp(
        theme: tablePlayTheme(),
        home: LoginScreen(store: store, onSignedIn: () {}),
      ),
    );
    expect(find.text('Staff sign in'), findsOneWidget);
    expect(
      find.textContaining('Admin, Counter, Kitchen, and Waiter'),
      findsOneWidget,
    );
    expect(find.byType(TextFormField), findsNWidgets(2));
    expect(find.text('Open my dashboard'), findsOneWidget);

    final credentialField = find.byKey(const Key('staff-credential-field'));
    var credential = tester.widget<EditableText>(
      find.descendant(of: credentialField, matching: find.byType(EditableText)),
    );
    expect(credential.obscureText, isTrue);

    await tester.tap(find.byKey(const Key('staff-credential-visibility')));
    await tester.pump();
    credential = tester.widget<EditableText>(
      find.descendant(of: credentialField, matching: find.byType(EditableText)),
    );
    expect(credential.obscureText, isFalse);

    final submit = find.text('Open my dashboard');
    await tester.ensureVisible(submit);
    await tester.tap(submit);
    await tester.pumpAndSettle();
    expect(find.text('Enter your username.'), findsOneWidget);
    expect(find.text('Enter your password.'), findsOneWidget);
  });
}
