import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tableplay_staff/features/staff_account_editor.dart';

const roles = [
  {'id': 1, 'display_name': 'Administrator'},
  {'id': 2, 'display_name': 'Counter'},
];
const staff = {
  'id': 2,
  'role_id': 2,
  'name': 'QA Staff',
  'username': 'qa.staff',
  'email': 'qa@example.test',
  'mobile': '1234567890',
};

Finder field(String label) => find.ancestor(
  of: find.byWidgetPredicate(
    (widget) => widget is TextField && widget.decoration?.labelText == label,
  ),
  matching: find.byType(TextFormField),
);

Future<void> openEditor(
  WidgetTester tester, {
  Map? user,
  int currentUserId = 1,
  required Future<void> Function(Map<String, dynamic>) onSave,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => FilledButton(
            onPressed: () => showModalBottomSheet<bool>(
              context: context,
              isScrollControlled: true,
              useSafeArea: true,
              builder: (_) => StaffAccountEditor(
                roles: roles,
                user: user,
                currentUserId: currentUserId,
                onSave: onSave,
              ),
            ),
            child: const Text('Open editor'),
          ),
        ),
      ),
    ),
  );
  await tester.tap(find.text('Open editor'));
  await tester.pumpAndSettle();
}

Future<void> tapVisible(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder);
  await tester.tap(finder);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
    'Create submits selected role and valid credentials on a narrow screen',
    (tester) async {
      tester.view.physicalSize = const Size(360, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      Map<String, dynamic>? saved;
      await openEditor(
        tester,
        onSave: (body) async {
          saved = body;
        },
      );
      await tapVisible(tester, find.byType(DropdownButtonFormField<int>));
      await tapVisible(tester, find.text('Counter').last);
      for (final entry in {
        'Full name': 'New QA Staff',
        'Username': 'new.qa',
        'Password': 'test-only-password',
        'PIN (optional)': '9876',
      }.entries) {
        await tester.ensureVisible(field(entry.key));
        await tester.enterText(field(entry.key), entry.value);
      }
      await tapVisible(tester, find.text('Create account'));
      expect(saved!['role_id'], 2);
      expect(saved!['name'], 'New QA Staff');
      expect(saved!['username'], 'new.qa');
      expect(saved!['password'], 'test-only-password');
      expect(saved!['pin'], '9876');
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );
  testWidgets('Create validates before contacting the API', (tester) async {
    var saves = 0;
    await openEditor(
      tester,
      onSave: (_) async {
        saves++;
      },
    );
    await tapVisible(tester, find.text('Create account'));
    expect(saves, 0);
    expect(find.text('Enter a full name.'), findsOneWidget);
    expect(find.text('Use at least 8 characters.'), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  for (final closeControl in ['Cancel', 'Close staff editor']) {
    testWidgets('$closeControl dismisses without saving or controller errors', (
      tester,
    ) async {
      var saves = 0;
      await openEditor(
        tester,
        user: staff,
        onSave: (_) async {
          saves++;
        },
      );
      await tapVisible(
        tester,
        closeControl == 'Cancel'
            ? find.text(closeControl)
            : find.byTooltip(closeControl),
      );
      expect(saves, 0);
      expect(find.byType(StaffAccountEditor), findsNothing);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    });
  }

  testWidgets(
    'Edit preserves blank credentials and submits the correct role and fields',
    (tester) async {
      Map<String, dynamic>? saved;
      await openEditor(
        tester,
        user: staff,
        onSave: (body) async {
          saved = body;
        },
      );
      await tester.enterText(field('Full name'), 'Updated QA');
      await tapVisible(tester, find.text('Save changes'));
      expect(saved!['name'], 'Updated QA');
      expect(saved!['role_id'], 2);
      expect(saved!.containsKey('password'), isFalse);
      expect(saved!.containsKey('pin'), isFalse);
      expect(saved!['clear_pin'], isFalse);
      expect(find.byType(StaffAccountEditor), findsNothing);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets('Password and PIN visibility toggles work in both directions', (
    tester,
  ) async {
    await openEditor(tester, user: staff, onSave: (_) async {});
    bool obscured(String label) => tester
        .widget<EditableText>(
          find.descendant(
            of: field(label),
            matching: find.byType(EditableText),
          ),
        )
        .obscureText;
    expect(obscured('New password'), isTrue);
    await tapVisible(tester, find.byTooltip('Show password'));
    expect(obscured('New password'), isFalse);
    await tapVisible(tester, find.byTooltip('Hide password'));
    expect(obscured('New password'), isTrue);
    await tapVisible(tester, find.byTooltip('Show PIN'));
    expect(obscured('PIN (optional)'), isFalse);
    await tapVisible(tester, find.byTooltip('Hide PIN'));
    expect(obscured('PIN (optional)'), isTrue);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('Remove PIN is explicit and the current account role is locked', (
    tester,
  ) async {
    Map<String, dynamic>? saved;
    await openEditor(
      tester,
      user: staff,
      currentUserId: 2,
      onSave: (body) async {
        saved = body;
      },
    );
    expect(
      tester
          .widget<DropdownButtonFormField<int>>(
            find.byType(DropdownButtonFormField<int>),
          )
          .onChanged,
      isNull,
    );
    await tapVisible(tester, find.text('Remove saved PIN'));
    expect(
      tester.widget<TextFormField>(field('PIN (optional)')).enabled,
      isFalse,
    );
    await tapVisible(tester, find.text('Save changes'));
    expect(saved!['clear_pin'], isTrue);
    expect(saved!.containsKey('pin'), isFalse);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('Server error stays in the editor and preserves entered data', (
    tester,
  ) async {
    await openEditor(
      tester,
      user: staff,
      onSave: (_) async {
        throw Exception('Username already exists.');
      },
    );
    await tester.enterText(field('Full name'), 'Keep my changes');
    await tapVisible(tester, find.text('Save changes'));
    expect(find.textContaining('Username already exists.'), findsOneWidget);
    expect(find.text('Keep my changes'), findsOneWidget);
    expect(find.byType(StaffAccountEditor), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('Repeated Save taps send only one request while busy', (
    tester,
  ) async {
    final completion = Completer<void>();
    var saves = 0;
    await openEditor(
      tester,
      user: staff,
      onSave: (_) {
        saves++;
        return completion.future;
      },
    );
    await tester.ensureVisible(find.text('Save changes'));
    await tester.tap(find.text('Save changes'));
    await tester.pump();
    expect(find.text('Saving…'), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(find.widgetWithText(FilledButton, 'Saving…'))
          .onPressed,
      isNull,
    );
    expect(
      tester
          .widget<OutlinedButton>(find.widgetWithText(OutlinedButton, 'Cancel'))
          .onPressed,
      isNull,
    );
    expect(saves, 1);
    completion.complete();
    await tester.pumpAndSettle();
    expect(saves, 1);
    expect(find.byType(StaffAccountEditor), findsNothing);
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
