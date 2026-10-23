import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

class ApiClient {
  ApiClient({
    required this.baseUrl,
    this.token,
    http.Client? client,
    this.requestTimeout = _timeout,
    this.retryDelay = const Duration(milliseconds: 500),
  }) : _client = client ?? http.Client();
  static const _timeout = Duration(seconds: 20);
  static const _uploadTimeout = Duration(seconds: 60);
  final http.Client _client;
  final Duration requestTimeout;
  final Duration retryDelay;
  bool _closed = false;
  String baseUrl;
  String? token;

  Future<dynamic> get(String path) async {
    final uri = _uri(path);
    final headers = _headers;
    for (var attempt = 0; attempt < 2; attempt++) {
      if (_closed) {
        throw const ApiConnectionException(
          'This connection was closed. Reopen the screen to reconnect.',
        );
      }
      try {
        final response = await _network(
          _client.get(uri, headers: headers),
          timeout: requestTimeout,
        );
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
      'The server is temporarily unavailable. Try again shortly.',
    );
  }

  Future<dynamic> post(
    String path, [
    Map<String, dynamic>? body,
    Duration? timeout,
  ]) async => _decode(
    await _network(
      _client.post(_uri(path), headers: _headers, body: jsonEncode(body ?? {})),
      timeout: timeout ?? requestTimeout,
      mutating: true,
    ),
  );
  Future<dynamic> put(String path, Map<String, dynamic> body) async => _decode(
    await _network(
      _client.put(_uri(path), headers: _headers, body: jsonEncode(body)),
      timeout: requestTimeout,
      mutating: true,
    ),
  );
  Future<dynamic> delete(String path) async => _decode(
    await _network(
      _client.delete(_uri(path), headers: _headers),
      timeout: requestTimeout,
      mutating: true,
    ),
  );
  Future<dynamic> uploadFile(
    String path, {
    required String field,
    required Uint8List bytes,
    required String filename,
  }) async {
    final request = http.MultipartRequest('POST', _uri(path));
    request.headers.addAll({
      'Accept': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    });
    request.files.add(
      http.MultipartFile.fromBytes(field, bytes, filename: filename),
    );
    final streamed = await _network(_client.send(request), mutating: true);
    return _decode(
      await _network(
        http.Response.fromStream(streamed),
        timeout: _uploadTimeout,
        mutating: true,
      ),
    );
  }

  void close() {
    _closed = true;
    _client.close();
  }

  Uri _uri(String path) => Uri.parse('$baseUrl$path');
  Map<String, String> get _headers => {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    if (token != null) 'Authorization': 'Bearer $token',
  };

  Future<T> _network<T>(
    Future<T> request, {
    Duration timeout = _timeout,
    bool mutating = false,
  }) async {
    try {
      return await request.timeout(timeout);
    } on TimeoutException {
      throw ApiConnectionException(
        'The server did not respond. Check the saved server address and network. '
        '${mutating ? 'The action may have been saved. Refresh and check before submitting again.' : 'Cloud servers may need time to wake up; local devices must use the restaurant Wi-Fi.'}',
      );
    } on SocketException {
      throw const ApiConnectionException(
        'The restaurant server cannot be reached. Check Wi-Fi and the host and port in the saved server address. Do not change the address unless your administrator confirms it.',
      );
    } on http.ClientException {
      throw const ApiConnectionException(
        'The restaurant server connection failed. Check the API address and try again.',
      );
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
            'The server is temporarily unavailable. Ask the administrator to check the server and database. Refresh before repeating an action.',
      });
    }
    if (response.statusCode >= 400) {
      throw ApiException(response.statusCode, body);
    }
    if (response.statusCode != 204 && body == null) {
      throw const ApiConnectionException(
        'The address did not return a valid TablePlay API response. Check the saved host, port and /api/v1 path.',
      );
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
  bool get isPlanRestriction =>
      status == 403 &&
      (errorCode == 'plan_feature_unavailable' ||
          errorCode == 'plan_limit_exceeded' ||
          feature != null);

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
