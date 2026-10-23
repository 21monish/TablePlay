import 'dart:async';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tableplay_tablet/core/api_client.dart';

void main() {
  test('read retries once on temporary server failure and preserves port',
      () async {
    var calls = 0;
    final client = MockClient((request) async {
      expect(
          request.url.toString(), 'http://restaurant.test:18740/api/v1/menu');
      calls++;
      return calls == 1
          ? http.Response('<html>Unavailable</html>', 503)
          : http.Response('{"ready":true}', 200);
    });
    final api = ApiClient(
        baseUrl: 'http://restaurant.test:18740/api/v1',
        client: client,
        retryDelay: Duration.zero);
    expect(await api.get('/menu'), {'ready': true});
    expect(calls, 2);
    api.close();
  });
  test('read retries once on network failure', () async {
    var calls = 0;
    final client = MockClient((_) async {
      calls++;
      throw const SocketException('offline');
    });
    final api = ApiClient(
        baseUrl: 'http://restaurant.test:18740/api/v1',
        client: client,
        retryDelay: Duration.zero);
    await expectLater(api.get('/menu'), throwsA(isA<ApiConnectionException>()));
    expect(calls, 2);
    api.close();
  });
  test('mutations are never replayed after transport failure', () async {
    var calls = 0;
    final client = MockClient((_) async {
      calls++;
      throw TimeoutException('uncertain outcome');
    });
    final api = ApiClient(
        baseUrl: 'http://restaurant.test:18740/api/v1',
        client: client,
        retryDelay: Duration.zero);
    await expectLater(() async {
      await api.post('/orders', {'items': []});
    },
        throwsA(isA<ApiConnectionException>().having((e) => e.message,
            'outcome warning', contains('may have been saved'))));
    expect(calls, 1);
    api.close();
  });
  test(
      'HTML server errors produce safe typed messages, not parser errors or HTML',
      () async {
    var calls = 0;
    final client = MockClient((_) async {
      calls++;
      return http.Response('<html>secret stacktrace</html>', 500);
    });
    final api = ApiClient(
        baseUrl: 'http://restaurant.test:18740/api/v1',
        client: client,
        retryDelay: Duration.zero);
    await expectLater(
        api.get('/menu'),
        throwsA(isA<ApiException>()
            .having((e) => e.status, 'HTTP status', 500)
            .having((e) => e.toString(), 'safe text',
                isNot(contains('stacktrace')))));
    expect(calls, 1);
    api.close();
  });
  test('authentication failures do not retry or become connection errors',
      () async {
    var calls = 0;
    final client = MockClient((_) async {
      calls++;
      return http.Response('{"message":"Please sign in again."}', 401);
    });
    final api = ApiClient(
        baseUrl: 'http://restaurant.test:18740/api/v1',
        client: client,
        retryDelay: Duration.zero);
    await expectLater(
        api.get('/menu'),
        throwsA(
            isA<ApiException>().having((e) => e.status, 'HTTP status', 401)));
    expect(calls, 1);
    api.close();
  });
  test('validation errors are preserved and never retried', () async {
    var calls = 0;
    final client = MockClient((_) async {
      calls++;
      return http.Response(
          '{"errors":{"items":["Select an available item."]}}', 422);
    });
    final api = ApiClient(
        baseUrl: 'http://restaurant.test:18740/api/v1',
        client: client,
        retryDelay: Duration.zero);
    await expectLater(() async {
      await api.post('/orders', {'items': []});
    },
        throwsA(isA<ApiException>().having(
            (e) => e.toString(), 'validation', 'Select an available item.')));
    expect(calls, 1);
    api.close();
  });
  test('invalid successful HTML is recoverable and retry is bounded', () async {
    var calls = 0;
    final client = MockClient((_) async {
      calls++;
      return http.Response('<html>not API</html>', 200);
    });
    final api = ApiClient(
        baseUrl: 'http://restaurant.test:18740/api/v1',
        client: client,
        retryDelay: Duration.zero);
    await expectLater(api.get('/menu'), throwsA(isA<ApiConnectionException>()));
    expect(calls, 2);
    api.close();
  });
  test('closing during retry prevents a second request', () async {
    var calls = 0;
    late ApiClient api;
    final client = MockClient((_) async {
      calls++;
      api.close();
      return http.Response('unavailable', 503);
    });
    api = ApiClient(
        baseUrl: 'http://restaurant.test:18740/api/v1',
        client: client,
        retryDelay: Duration.zero);
    await expectLater(api.get('/menu'), throwsA(isA<ApiConnectionException>()));
    expect(calls, 1);
  });
}
