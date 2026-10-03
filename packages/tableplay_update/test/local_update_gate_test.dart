import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tableplay_update/tableplay_update.dart';

void main() {
  test('release metadata parses checksum, policy, and package details', () {
    final release = LocalAppUpdate.fromJson({
      'latest_version': '1.2.0',
      'latest_build_number': 3,
      'mandatory': true,
      'download_url':
          'http://10.69.104.253:8000/api/v1/app-updates/download/staff-android',
      'sha256': 'ABCDEF',
      'file_size': 1024,
      'release_notes': 'Navigation and updater improvements.',
    });

    expect(release.version, '1.2.0');
    expect(release.buildNumber, 3);
    expect(release.mandatory, isTrue);
    expect(release.sha256, 'abcdef');
    expect(release.fileSize, 1024);
  });

  testWidgets('update gate preserves its application child', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: LocalUpdateGate(
          app: 'staff',
          baseUrl: 'http://127.0.0.1:8000/api/v1',
          installationUuid: '1e47d87e-b734-4848-9137-997ad469bb47',
          deviceName: 'Test staff app',
          checkOnStart: false,
          child: Scaffold(body: Text('Staff workspace')),
        ),
      ),
    );

    expect(find.text('Staff workspace'), findsOneWidget);
  });
}
