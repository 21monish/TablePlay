import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;

class ApiClient {
  ApiClient({this.baseUrl = ''});
  static const _timeout = Duration(seconds: 20);
  final http.Client _client = http.Client();
  String baseUrl;
  String? deviceUuid;
  String? deviceToken;

  Future<dynamic> get(String path) async => _decode(await _network(
      _client.get(Uri.parse('$baseUrl$path'), headers: _headers)));
  Future<dynamic> post(String path, Map<String, dynamic> body) async =>
      _decode(await _network(_client.post(Uri.parse('$baseUrl$path'),
          headers: _headers, body: jsonEncode(body))));
  void close() => _client.close();
  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (deviceUuid != null) 'X-Device-UUID': deviceUuid!,
        if (deviceToken != null) 'Authorization': 'Bearer $deviceToken'
      };

  Future<T> _network<T>(Future<T> request) async {
    try {
      return await request.timeout(_timeout);
    } on TimeoutException {
      throw const ApiConnectionException(
          'The restaurant server did not respond. Check Wi-Fi and pair again using a new Admin QR code.');
    } on SocketException {
      throw const ApiConnectionException(
          'The restaurant server cannot be reached. Confirm the laptop and tablet use the same Wi-Fi.');
    } on http.ClientException {
      throw const ApiConnectionException(
          'The restaurant server connection failed. Pair again using a new Admin QR code.');
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
