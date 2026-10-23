import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;

class ApiClient {
  ApiClient(
      {this.baseUrl = '',
      http.Client? client,
      this.requestTimeout = _timeout,
      this.retryDelay = const Duration(milliseconds: 500)})
      : _client = client ?? http.Client();
  static const _timeout = Duration(seconds: 20);
  final http.Client _client;
  final Duration requestTimeout;
  final Duration retryDelay;
  bool _closed = false;
  String baseUrl;
  String? deviceUuid;
  String? deviceToken;

  Future<dynamic> get(String path) async {
    final uri = Uri.parse('$baseUrl$path');
    final headers = _headers;
    for (var attempt = 0; attempt < 2; attempt++) {
      if (_closed) {
        throw const ApiConnectionException(
            'This connection was closed. Reopen the screen to reconnect.');
      }
      try {
        final response = await _network(_client.get(uri, headers: headers));
        if (attempt == 0 && [502, 503, 504].contains(response.statusCode)) {
          await Future<void>.delayed(retryDelay);
          continue;
        }
        return _decode(response);
      } on ApiConnectionException {
        if (attempt == 1 || _closed) rethrow;
        await Future<void>.delayed(retryDelay);
      }
    }
    throw const ApiConnectionException(
        'The server is temporarily unavailable. Try again shortly.');
  }

  Future<dynamic> post(String path, Map<String, dynamic> body) async =>
      _decode(await _network(
          _client.post(Uri.parse('$baseUrl$path'),
              headers: _headers, body: jsonEncode(body)),
          mutating: true));
  void close() {
    _closed = true;
    _client.close();
  }

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (deviceUuid != null) 'X-Device-UUID': deviceUuid!,
        if (deviceToken != null) 'Authorization': 'Bearer $deviceToken'
      };

  Future<T> _network<T>(Future<T> request, {bool mutating = false}) async {
    try {
      return await request.timeout(requestTimeout);
    } on TimeoutException {
      throw ApiConnectionException(
          'The restaurant server did not respond. Check Wi-Fi and the saved server address. '
          '${mutating ? 'The action may have been saved. Check My orders or ask staff before submitting again.' : 'Your pairing is still saved. Retry when the server is ready.'}');
    } on SocketException {
      throw const ApiConnectionException(
          'The restaurant server cannot be reached. Confirm the laptop and tablet use the same Wi-Fi.');
    } on http.ClientException {
      throw const ApiConnectionException(
          'The restaurant server connection failed. Your pairing is still saved. Check Wi-Fi and retry; only pair again if your administrator asks.');
    }
  }

  dynamic _decode(http.Response response) {
    dynamic body;
    try {
      body = response.body.isEmpty ? null : jsonDecode(response.body);
    } on FormatException {
      body = null;
    }
    if (response.statusCode >= 500) {
      throw ApiException(response.statusCode, {
        'message':
            'The restaurant server is temporarily unavailable. Ask staff to check the server and database. Check My orders before repeating an order.'
      });
    }
    if (response.statusCode >= 400) {
      throw ApiException(response.statusCode, body);
    }
    if (response.statusCode != 204 && body == null) {
      throw const ApiConnectionException(
          'The saved address did not return a valid TablePlay API response. Ask staff to check the host, port and /api/v1 path. Your pairing has not been removed.');
    }
    return body;
  }
}

class ApiConnectionException implements Exception {
  const ApiConnectionException(this.message);
  final String message;

  @override
  String toString() => message;
}

class ApiException implements Exception {
  ApiException(this.status, this.body);
  final int status;
  final dynamic body;

  Map<String, dynamic> get details => body is Map
      ? Map<String, dynamic>.from(body as Map)
      : <String, dynamic>{};

  String? get errorCode => details['error']?.toString();
  String? get feature => details['feature']?.toString();
  bool get isPlanAccessDenied =>
      status == 403 &&
      (errorCode == 'plan_feature_unavailable' ||
          errorCode == 'plan_limit_exceeded' ||
          feature == 'customer_app' ||
          feature == null);

  @override
  String toString() {
    if (body is Map) {
      final errors = body['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final first = errors.values.first;
        if (first is List && first.isNotEmpty) return first.first.toString();
      }
      return body['message']?.toString() ?? 'Request failed ($status)';
    }
    return 'Request failed ($status)';
  }
}
