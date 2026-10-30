import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tableplay_staff/core/api_client.dart';
import 'package:tableplay_staff/core/session_store.dart';
import 'package:tableplay_staff/features/staff_home.dart';
import 'package:tableplay_staff/theme/tableplay_theme.dart';

Future<List<http.Request>> openTeam(
  WidgetTester tester, {
  required bool mobile,
  bool rejectDelete = false,
}) async {
  tester.view.physicalSize = mobile
      ? const Size(360, 900)
      : const Size(1400, 1100);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  SharedPreferences.setMockInitialValues({
    'base_url': 'http://restaurant.test:18740/api/v1',
    'staff_token': 'test-only-token',
    'staff_role': 'admin',
    'staff_name': 'QA Admin',
    'staff_id': 1,
  });
  final store = SessionStore(await SharedPreferences.getInstance());
  final requests = <http.Request>[];
  final users = <Map<String, dynamic>>[
    {
      'id': 1,
      'role_id': 1,
      'name': 'QA Admin',
      'username': 'qa.admin',
      'is_active': true,
      'role': {'display_name': 'Administrator'},
    },
    {
      'id': 2,
      'role_id': 2,
      'name': 'QA Staff',
      'username': 'qa.staff',
      'is_active': true,
      'role': {'display_name': 'Counter'},
    },
  ];
  const entitlements = {
    'licensed': true,
    'plan': {'name': 'Trial'},
    'features': <String, dynamic>{},
  };
  final api = ApiClient(
    baseUrl: store.baseUrl,
    token: store.token,
    client: MockClient((request) async {
      requests.add(request);
      if (request.method == 'DELETE') {
        if (rejectDelete) {
          return http.Response(
            jsonEncode({'message': 'Deletion refused by the server.'}),
            422,
          );
        }
        users.removeWhere((user) => user['id'] == 2);
        return http.Response(jsonEncode({'message': 'Deleted.'}), 200);
      }
      if (request.method == 'POST' && request.url.path.endsWith('/toggle')) {
        users[1]['is_active'] = users[1]['is_active'] != true;
        return http.Response(jsonEncode(users[1]), 200);
      }
      if (request.method == 'PUT') {
        users[1].addAll(Map<String, dynamic>.from(jsonDecode(request.body)));
        return http.Response(
          jsonEncode({'user': users[1], 'message': 'Updated.'}),
          200,
        );
      }
      final body = switch (request.url.path) {
        '/api/v1/branding' => {'restaurant_name': 'QA Restaurant'},
        '/api/v1/admin/team' => {
          'entitlements': entitlements,
          'staff': users,
          'roles': [
            {'id': 1, 'display_name': 'Administrator'},
            {'id': 2, 'display_name': 'Counter'},
          ],
        },
        _ => {
          'entitlements': entitlements,
          'stats': {
            'sales_today': 0,
            'orders_today': 0,
            'open_tables': 0,
            'pending_orders': 0,
          },
          'tables': [],
          'recent_orders': [],
          'requests': [],
          'devices': [],
        },
      };
      return http.Response(jsonEncode(body), 200);
    }),
  );
  await tester.pumpWidget(
    MaterialApp(
      theme: tablePlayTheme(),
      home: StaffHome(store: store, apiClient: api, onSignedOut: () {}),
    ),
  );
  await tester.pumpAndSettle();
  if (mobile) {
    await tester.tap(find.byTooltip('Open navigation menu'));
    await tester.pumpAndSettle();
  }
  await tester.tap(find.text('Team'));
  await tester.pumpAndSettle();
  return requests;
}

Future<void> tapVisible(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder);
  await tester.tap(finder);
  await tester.pumpAndSettle();
}

Finder staffSwitch() => find.ancestor(
  of: find.text('QA Staff'),
  matching: find.byType(SwitchListTile),
);

void main() {
  for (final mobile in [false, true]) {
    testWidgets(
      '${mobile ? 'Mobile' : 'Desktop'} Team: toggle Cancel/Confirm, Delete Cancel/Confirm and self protection',
      (tester) async {
        final requests = await openTeam(tester, mobile: mobile);
        expect(find.text('Edit'), findsNWidgets(2));
        expect(find.text('Delete'), findsOneWidget);
        expect(find.text('Current account'), findsOneWidget);
        final ownSwitch = find.ancestor(
          of: find.text('QA Admin'),
          matching: find.byType(SwitchListTile),
        );
        expect(tester.widget<SwitchListTile>(ownSwitch).onChanged, isNull);
        await tapVisible(tester, staffSwitch());
        expect(find.text('Disable QA Staff?'), findsOneWidget);
        await tapVisible(tester, find.text('Cancel'));
        expect(requests.where((r) => r.method != 'GET'), isEmpty);
        await tapVisible(tester, staffSwitch());
        await tapVisible(tester, find.text('Confirm'));
        expect(tester.widget<SwitchListTile>(staffSwitch()).value, isFalse);
        await tapVisible(tester, staffSwitch());
        expect(find.text('Enable QA Staff?'), findsOneWidget);
        await tapVisible(tester, find.text('Confirm'));
        expect(tester.widget<SwitchListTile>(staffSwitch()).value, isTrue);
        expect(
          requests
              .where(
                (r) =>
                    r.method == 'POST' &&
                    r.url.path.endsWith('/staff/2/toggle'),
              )
              .length,
          2,
        );
        await tapVisible(tester, find.text('Delete'));
        expect(find.text('Delete QA Staff?'), findsOneWidget);
        expect(
          find.textContaining(
            'Past orders, bills and audit records will be kept.',
          ),
          findsOneWidget,
        );
        await tapVisible(tester, find.text('Cancel'));
        expect(requests.where((r) => r.method == 'DELETE'), isEmpty);
        await tapVisible(tester, find.text('Delete'));
        await tapVisible(tester, find.text('Delete account'));
        expect(
          requests
              .where(
                (r) => r.method == 'DELETE' && r.url.path.endsWith('/staff/2'),
              )
              .length,
          1,
        );
        expect(find.text('QA Staff'), findsNothing);
        expect(tester.takeException(), isNull);
        await tester.pumpWidget(const SizedBox.shrink());
      },
    );
  }

  testWidgets(
    'Edit opens populated form, saves with PUT and refreshes the directory',
    (tester) async {
      final requests = await openTeam(tester, mobile: false);
      await tapVisible(tester, find.text('Edit').last);
      expect(find.text('Edit staff account'), findsOneWidget);
      final name = find.byWidgetPredicate(
        (widget) =>
            widget is TextField && widget.decoration?.labelText == 'Full name',
      );
      await tester.enterText(name, 'Renamed QA Staff');
      await tapVisible(tester, find.text('Save changes'));
      final writes = requests.where((r) => r.method == 'PUT').toList();
      expect(writes.length, 1);
      expect(writes.single.url.path, '/api/v1/admin/staff/2');
      expect(jsonDecode(writes.single.body)['name'], 'Renamed QA Staff');
      expect(find.text('Renamed QA Staff'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets(
    'Delete failure shows the server error and keeps the staff card',
    (tester) async {
      final requests = await openTeam(tester, mobile: true, rejectDelete: true);
      await tapVisible(tester, find.text('Delete'));
      await tapVisible(tester, find.text('Delete account'));
      expect(requests.where((r) => r.method == 'DELETE').length, 1);
      expect(find.text('Deletion refused by the server.'), findsOneWidget);
      expect(find.text('QA Staff'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );
}
