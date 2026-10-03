import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tableplay_tablet/core/api_client.dart';
import 'package:tableplay_tablet/core/game_access_lease.dart';
import 'package:tableplay_tablet/features/game_center_v2.dart';
import 'package:tableplay_tablet/theme/tableplay_theme.dart';

void main() {
  group('GameAccessLease', () {
    late DateTime now;
    late GameAccessLease lease;

    setUp(() {
      now = DateTime.utc(2026, 9, 3, 12);
      lease = GameAccessLease(now: () => now);
    });

    test('uses a conservative server-approved deadline', () {
      lease.applyServerState(
        {
          'unlocked': true,
          'server_time': '2026-09-03T12:00:00Z',
          'expires_at': '2026-09-03T12:01:00Z',
          'remaining_seconds': 60,
        },
        requestStartedAt: now.subtract(const Duration(milliseconds: 500)),
      );

      expect(lease.unlocked, isTrue);
      expect(
        lease.deadline,
        DateTime.utc(2026, 9, 3, 12).add(
          const Duration(seconds: 59, milliseconds: 500),
        ),
      );

      now = lease.deadline!.subtract(const Duration(milliseconds: 1));
      expect(lease.unlocked, isTrue);
      now = lease.deadline!;
      expect(lease.unlocked, isFalse);
      expect(lease.remainingSeconds, 0);
      expect(lease.reason, 'timer');
    });

    test('a transient failure cannot extend play beyond cached expiry', () {
      lease.applyServerState({
        'unlocked': true,
        'server_time': now.toIso8601String(),
        'expires_at': now.add(const Duration(seconds: 10)).toIso8601String(),
        'remaining_seconds': 10,
      });

      now = now.add(const Duration(seconds: 9));
      lease.refresh();
      expect(lease.unlocked, isTrue);
      expect(lease.remainingSeconds, 1);

      // A timeout/connection failure does not apply a new server state.
      now = now.add(const Duration(seconds: 1));
      lease.refresh();
      expect(lease.unlocked, isFalse);
      expect(lease.reason, 'timer');
    });

    test('401, 403 and 404 revoke immediately with a useful reason', () {
      for (final expectation in const {
        401: 'access',
        403: 'plan',
        404: 'session',
      }.entries) {
        lease.applyServerState({
          'unlocked': true,
          'server_time': now.toIso8601String(),
          'expires_at': now.add(const Duration(minutes: 30)).toIso8601String(),
          'remaining_seconds': 1800,
        });

        expect(lease.revokeForHttpStatus(expectation.key), isTrue);
        expect(lease.unlocked, isFalse);
        expect(lease.reason, expectation.value);
      }
    });

    test('a server-locked response ends access immediately during billing', () {
      lease.applyServerState({
        'unlocked': true,
        'server_time': now.toIso8601String(),
        'expires_at': now.add(const Duration(minutes: 30)).toIso8601String(),
        'remaining_seconds': 1800,
      });
      expect(lease.unlocked, isTrue);

      // GameAccessService returns this state as soon as the table moves from
      // open to billing, even before cash payment closes the table session.
      lease.applyServerState({
        'unlocked': false,
        'server_time': now.toIso8601String(),
        'expires_at': null,
        'remaining_seconds': 0,
      });

      expect(lease.unlocked, isFalse);
      expect(lease.remainingSeconds, 0);
      expect(lease.reason, 'timer');
    });

    test('server errors never grant or lengthen access', () {
      lease.applyServerState({
        'unlocked': true,
        'server_time': now.toIso8601String(),
        'expires_at': now.add(const Duration(seconds: 5)).toIso8601String(),
        'remaining_seconds': 5,
      });
      final originalDeadline = lease.deadline;

      expect(lease.revokeForHttpStatus(500), isFalse);
      expect(lease.unlocked, isTrue);
      expect(lease.deadline, originalDeadline);

      now = now.add(const Duration(seconds: 5));
      expect(lease.unlocked, isFalse);
    });

    test('an unverifiable unlocked response stays locked', () {
      lease.applyServerState({'unlocked': true});

      expect(lease.unlocked, isFalse);
      expect(lease.reason, 'access');
    });
  });

  testWidgets('a running game closes when the server loses its table session',
      (tester) async {
    final now = DateTime.now().toUtc();
    final access = {
      'unlocked': true,
      'server_time': now.toIso8601String(),
      'expires_at': now.add(const Duration(minutes: 20)).toIso8601String(),
      'remaining_seconds': 1200,
    };
    final api = _RevokingApi(access);

    await tester.pumpWidget(
      MaterialApp(
        theme: tablePlayTheme(),
        home: Scaffold(body: GameCenterV2(api: api, access: access)),
      ),
    );
    await tester.pump();

    expect(find.text('Play'), findsOneWidget);
    await tester.tap(find.text('Play'));
    await tester.pump();
    await tester.pump();

    expect(find.text('Game session ended'), findsOneWidget);
    expect(find.text('Game access has ended'), findsOneWidget);
    expect(
      find.textContaining('table session or tablet pairing'),
      findsOneWidget,
    );

    await tester.pumpWidget(const SizedBox.shrink());
    api.close();
  });
}

class _RevokingApi extends ApiClient {
  _RevokingApi(this.access);

  final Map<String, dynamic> access;
  int accessCalls = 0;

  @override
  Future<dynamic> get(String path) async {
    if (path == '/games') {
      return [
        {
          'id': 1,
          'name': 'Tap Challenge',
          'slug': 'tap-challenge',
          'description': 'Test game',
          'player_mode': 'single',
        }
      ];
    }
    if (path == '/games/access') {
      accessCalls++;
      if (accessCalls == 1) return Map<String, dynamic>.from(access);
      throw ApiException(404, {'message': 'No active table session.'});
    }
    throw StateError('Unexpected API path: $path');
  }
}
