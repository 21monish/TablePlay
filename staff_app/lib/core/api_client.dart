import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

class ApiClient {
  ApiClient({required this.baseUrl, this.token});
  static const _timeout = Duration(seconds: 20);
  static const _uploadTimeout = Duration(seconds: 60);
  final http.Client _client = http.Client();
  String baseUrl;
  String? token;

  Future<dynamic> get(String path) async =>
      _decode(await _network(_client.get(_uri(path), headers: _headers)));
  Future<dynamic> post(String path, [Map<String, dynamic>? body]) async =>
      _decode(
        await _network(
          _client.post(
            _uri(path),
            headers: _headers,
            body: jsonEncode(body ?? {}),
          ),
        ),
      );
  Future<dynamic> put(String path, Map<String, dynamic> body) async => _decode(
    await _network(
      _client.put(_uri(path), headers: _headers, body: jsonEncode(body)),
    ),
  );
  Future<dynamic> delete(String path) async =>
      _decode(await _network(_client.delete(_uri(path), headers: _headers)));
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
    final streamed = await _network(_client.send(request));
    return _decode(
      await _network(
        http.Response.fromStream(streamed),
        timeout: _uploadTimeout,
      ),
    );
  }

  void close() => _client.close();
  Uri _uri(String path) => Uri.parse('$baseUrl$path');
  Map<String, String> get _headers => {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    if (token != null) 'Authorization': 'Bearer $token',
  };

  Future<T> _network<T>(
    Future<T> request, {
    Duration timeout = _timeout,
  }) async {
    try {
      return await request.timeout(timeout);
    } on TimeoutException {
      throw const ApiConnectionException(
        'The restaurant server did not respond. Check the server address and make sure this device is on the same Wi-Fi.',
      );
    } on SocketException {
      throw const ApiConnectionException(
        'The restaurant server cannot be reached. Check Wi-Fi, the laptop IP, and port 8000.',
      );
    } on http.ClientException {
      throw const ApiConnectionException(
        'The restaurant server connection failed. Check the API address and try again.',
      );
    }
  }

  dynamic _decode(http.Response response) {
    final body = response.body.isEmpty ? null : jsonDecode(response.body);
    if (response.statusCode >= 400) {
      throw ApiException(response.statusCode, body);
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
