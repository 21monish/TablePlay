import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tableplay_staff/core/api_client.dart';
import 'package:tableplay_staff/core/session_store.dart';
import 'package:tableplay_staff/features/admin_experience.dart';
import 'package:tableplay_staff/features/staff_home.dart';
import 'package:tableplay_staff/theme/tableplay_theme.dart';

final licence = <String, dynamic>{
  'entitlements': {
    'licensed': true,
    'signature_valid': true,
    'plan': {'name': 'Free Trial'},
    'status': 'trial',
    'days_remaining': 13,
    'subscription_expires_at': '2026-10-18T12:00:00Z',
    'offline_verification_due_at': '2026-10-10T12:00:00Z',
    'features': {'customer_app': true, 'games': false},
    'limits': {'paired_tables': 3},
    'usage': {'paired_tables': 1},
  },
  'installation': {
    'cloud_url': 'https://cloud.tableplay.test',
    'online_activated': true,
    'installation_uuid': 'test-installation',
  },
  'sync_logs': <dynamic>[],
};

Widget host(Widget child) => MaterialApp(
  theme: tablePlayTheme(),
  home: Scaffold(body: SingleChildScrollView(child: child)),
);

void main() {
  testWidgets(
    'mobile menu keeps a named custom delete dialog and Cancel never deletes',
    (tester) async {
      tester.view.physicalSize = const Size(360, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      SharedPreferences.setMockInitialValues({
        'base_url': 'http://restaurant.test:18740/api/v1',
        'staff_token': 'test-token',
        'staff_role': 'admin',
        'staff_name': 'Admin',
        'staff_id': 1,
      });
      final store = SessionStore(await SharedPreferences.getInstance());
      var deletions = 0;
      final api = ApiClient(
        baseUrl: store.baseUrl,
        token: store.token,
        client: MockClient((request) async {
          if (request.method == 'DELETE') deletions++;
          final body = switch (request.url.path) {
            '/api/v1/branding' => {'restaurant_name': 'Ganesh'},
            '/api/v1/admin/catalog' => {
              'entitlements': licence['entitlements'],
              'games': [],
              'categories': [
                {
                  'name': 'Main course',
                  'menu_items': [
                    {
                      'id': 1,
                      'name': 'Masala Dosa',
                      'price': '100.00',
                      'food_type': 'veg',
                      'is_available': true,
                      'is_bestseller': false,
                    },
                  ],
                },
              ],
            },
            _ => {
              'entitlements': licence['entitlements'],
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
      await tester.tap(find.byTooltip('Open navigation menu'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Menu & games'));
      await tester.pumpAndSettle();
      expect(find.text('Masala Dosa'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.ensureVisible(find.byTooltip('Menu item actions'));
      await tester.tap(find.byTooltip('Menu item actions'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Delete item'));
      await tester.pumpAndSettle();
      expect(find.text('Delete Masala Dosa?'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();
      expect(deletions, 0);
      expect(find.text('Masala Dosa'), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets(
    'receipt preview uses server totals and preserves historical item names',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: BillDetailsDialog(
              session: {
                'dining_table': {'table_code': 'T01'},
                'session_code': 'SESSION-1',
                'bill': {
                  'bill_number': 'BILL-1',
                  'subtotal': '100.00',
                  'tax_amount': '5.00',
                  'discount_amount': '0.00',
                  'grand_total': '105.00',
                  'payment_status': 'unpaid',
                },
                'orders': [
                  {
                    'order_number': 'ORDER-1',
                    'status': 'served',
                    'items': [
                      {
                        'quantity': 1,
                        'item_name_snapshot': 'Historical dish name',
                      },
                    ],
                  },
                ],
              },
            ),
          ),
        ),
      );
      expect(find.text('105.00'), findsOneWidget);
      expect(find.text('1 x Historical dish name'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  test('menu search combines case-insensitive text and availability', () {
    final item = {
      'name': 'Masala Dosa',
      'ingredients': 'Rice, lentils',
      'is_available': true,
    };
    expect(menuMatches(item, ' RICE ', 'available'), isTrue);
    expect(menuMatches(item, 'dosa', 'unavailable'), isFalse);
    expect(menuMatches(item, 'pizza', 'all'), isFalse);
    expect(menuMatches(item, '', 'all'), isTrue);
  });

  testWidgets(
    'mobile licence layout separates renewal and offline verification',
    (tester) async {
      tester.view.physicalSize = const Size(360, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(
        host(
          LicensePanel(
            data: licence,
            onActivate: (_, __) async {},
            onSync: () async {},
            onExport: () async {},
            onImport: () async {},
          ),
        ),
      );
      expect(find.text('13 days remaining'), findsOneWidget);
      expect(find.text('Subscription expires:'), findsOneWidget);
      expect(find.text('Offline verification due:'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'activation validates empty fields before contacting the server',
    (tester) async {
      var calls = 0;
      await tester.pumpWidget(
        host(
          LicensePanel(
            data: licence,
            onActivate: (_, __) async {
              calls++;
            },
            onSync: () async {},
            onExport: () async {},
            onImport: () async {},
          ),
        ),
      );
      await tester.ensureVisible(find.text('Activate and verify'));
      await tester.tap(find.text('Activate and verify'));
      await tester.pump();
      expect(calls, 0);
      expect(
        find.text('Enter the activation key supplied by TablePlay.'),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'licence action blocks duplicate taps and displays server errors',
    (tester) async {
      final completion = Completer<void>();
      var calls = 0;
      await tester.pumpWidget(
        host(
          LicensePanel(
            data: licence,
            onActivate: (_, __) async {},
            onSync: () {
              calls++;
              return completion.future;
            },
            onExport: () async {},
            onImport: () async {},
          ),
        ),
      );
      await tester.ensureVisible(find.text('Synchronize now'));
      await tester.tap(find.text('Synchronize now'));
      await tester.pump();
      await tester.tap(find.text('Synchronize now'));
      expect(calls, 1);
      completion.completeError(
        ApiException(422, {'message': 'Signature verification failed.'}),
      );
      await tester.pumpAndSettle();
      expect(find.text('Signature verification failed.'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('expired pairing QR cannot remain visible', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ConnectionQrDialog(
            payload: {
              'type': 'tableplay_pairing',
              'table_code': 'T01',
              'server_name': 'Restaurant',
              'api_base_url': 'http://192.168.50.10:8000/api/v1',
              'expires_at': '2020-01-01T00:00:00Z',
            },
          ),
        ),
      ),
    );
    expect(
      find.text('This QR has expired. Close it and generate a new one.'),
      findsOneWidget,
    );
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets(
    '401 offers sign-in recovery without pretending the server is offline',
    (tester) async {
      SharedPreferences.setMockInitialValues({
        'base_url': 'http://restaurant.test:18740/api/v1',
        'staff_token': 'test-token',
        'staff_role': 'admin',
        'staff_name': 'Admin',
        'staff_id': 1,
      });
      final store = SessionStore(await SharedPreferences.getInstance());
      var signedOut = false;
      final api = ApiClient(
        baseUrl: store.baseUrl,
        token: store.token,
        client: MockClient(
          (request) async =>
              http.Response(jsonEncode({'message': 'Unauthenticated.'}), 401),
        ),
      );
      await tester.pumpWidget(
        MaterialApp(
          theme: tablePlayTheme(),
          home: StaffHome(
            store: store,
            apiClient: api,
            onSignedOut: () => signedOut = true,
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Sign in required'), findsOneWidget);
      expect(find.text('Sign in again'), findsOneWidget);
      expect(find.text('Connection issue'), findsNothing);
      await tester.tap(find.text('Sign in again'));
      await tester.pumpAndSettle();
      expect(signedOut, isTrue);
      expect(store.signedIn, isFalse);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets(
    'admin navigation opens native licence, setup and billing screens',
    (tester) async {
      tester.view.physicalSize = const Size(1400, 1100);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      SharedPreferences.setMockInitialValues({
        'base_url': 'http://restaurant.test:18740/api/v1',
        'staff_token': 'test-token',
        'staff_role': 'admin',
        'staff_name': 'Admin',
        'staff_id': 1,
      });
      final store = SessionStore(await SharedPreferences.getInstance());
      final paths = <String>[];
      final api = ApiClient(
        baseUrl: store.baseUrl,
        token: store.token,
        client: MockClient((request) async {
          paths.add(request.url.path);
          final body = switch (request.url.path) {
            '/api/v1/branding' => {'restaurant_name': 'Ganesh'},
            '/api/v1/admin/license' => licence,
            '/api/v1/admin/setup' => {
              'entitlements': licence['entitlements'],
              'steps': [],
              'completed': 0,
              'total': 7,
              'percent': 0,
              'releases': [],
            },
            '/api/v1/counter/dashboard' => {
              'entitlements': licence['entitlements'],
              'orders': [],
              'sessions': [],
              'service_requests': [],
              'game_sessions': [],
            },
            _ => {
              'entitlements': licence['entitlements'],
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
      for (final label in ['Licence', 'Setup guide', 'Orders & billing']) {
        final navigation = find.descendant(
          of: find.byKey(const Key('staff-navigation')),
          matching: find.text(label),
        );
        await tester.scrollUntilVisible(
          navigation,
          140,
          scrollable: find
              .descendant(
                of: find.byKey(const Key('staff-navigation')),
                matching: find.byType(Scrollable),
              )
              .first,
        );
        await Scrollable.ensureVisible(
          tester.element(navigation),
          alignment: .5,
        );
        await tester.pumpAndSettle();
        await tester.tap(navigation);
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
      }
      expect(
        paths,
        containsAll([
          '/api/v1/admin/license',
          '/api/v1/admin/setup',
          '/api/v1/counter/dashboard',
        ]),
      );
      expect(find.text('No open tables to bill'), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );
}
