import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:open_filex/open_filex.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';

class LocalAppUpdate {
  const LocalAppUpdate({
    required this.version,
    required this.buildNumber,
    required this.mandatory,
    required this.downloadUrl,
    required this.sha256,
    required this.fileSize,
    required this.releaseNotes,
  });

  final String version;
  final int? buildNumber;
  final bool mandatory;
  final Uri downloadUrl;
  final String sha256;
  final int fileSize;
  final String releaseNotes;

  factory LocalAppUpdate.fromJson(Map<String, dynamic> json) => LocalAppUpdate(
        version: json['latest_version'].toString(),
        buildNumber: (json['latest_build_number'] as num?)?.toInt(),
        mandatory: json['mandatory'] == true,
        downloadUrl: Uri.parse(json['download_url'].toString()),
        sha256: json['sha256'].toString().toLowerCase(),
        fileSize: (json['file_size'] as num).toInt(),
        releaseNotes: json['release_notes']?.toString().trim() ?? '',
      );
}

class LocalUpdateGate extends StatefulWidget {
  const LocalUpdateGate({
    super.key,
    required this.app,
    required this.baseUrl,
    required this.installationUuid,
    required this.deviceName,
    required this.child,
    this.headers = const {},
    this.accentColor,
    this.checkOnStart = true,
  });

  final String app;
  final String baseUrl;
  final String installationUuid;
  final String deviceName;
  final Map<String, String> headers;
  final Color? accentColor;
  final bool checkOnStart;
  final Widget child;

  static Future<void> check(BuildContext context, {bool showCurrent = true}) {
    final scope = context
        .getElementForInheritedWidgetOfExactType<_LocalUpdateScope>()
        ?.widget as _LocalUpdateScope?;
    return scope?.state.check(showCurrent: showCurrent) ?? Future.value();
  }

  @override
  State<LocalUpdateGate> createState() => _LocalUpdateGateState();
}

class _LocalUpdateGateState extends State<LocalUpdateGate>
    with WidgetsBindingObserver {
  bool checking = false;
  bool downloading = false;
  double? progress;
  _UpdateNotice? notice;
  Object? noticeIdentity;
  String progressLabel = 'Preparing download…';

  String get platform => Platform.isWindows ? 'windows' : 'android';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await _showRecoveredWindowsError();
      if (widget.checkOnStart) await check();
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && widget.checkOnStart) {
      Future<void>.delayed(const Duration(milliseconds: 750), () => check());
    }
  }

  Future<void> _showRecoveredWindowsError() async {
    if (!Platform.isWindows) return;
    final marker = File(
      '${File(Platform.resolvedExecutable).parent.path}${Platform.pathSeparator}.tableplay_update_error',
    );
    if (!await marker.exists()) return;

    var detail = 'The previous version has been restored.';
    try {
      final saved = (await marker.readAsString()).trim();
      if (saved.isNotEmpty) detail = saved;
      await marker.delete();
    } catch (_) {
      // Keep the safe fallback message if the marker cannot be read or removed.
    }

    if (mounted) {
      await _showError('The update was rolled back safely. $detail');
    }
  }

  @override
  void didUpdateWidget(covariant LocalUpdateGate oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.checkOnStart &&
        (oldWidget.baseUrl != widget.baseUrl ||
            oldWidget.installationUuid != widget.installationUuid ||
            oldWidget.deviceName != widget.deviceName ||
            !mapEquals(oldWidget.headers, widget.headers))) {
      WidgetsBinding.instance.addPostFrameCallback((_) => check());
    }
  }

  Future<void> check({bool showCurrent = false}) async {
    if (checking || downloading || !mounted) return;
    setState(() => checking = true);

    try {
      if (!Platform.isAndroid && !Platform.isWindows) return;
      final package = await PackageInfo.fromPlatform();
      final endpoint = Uri.parse(
        '${widget.baseUrl.replaceAll(RegExp(r'/+$'), '')}/app-updates/check',
      ).replace(
        queryParameters: {
          'app': widget.app,
          'platform': platform,
          'current_version': package.version,
          'build_number': package.buildNumber,
          'installation_uuid': widget.installationUuid,
          'device_name': widget.deviceName,
        },
      );
      final response = await http.get(
        endpoint,
        headers: {'Accept': 'application/json', ...widget.headers},
      ).timeout(const Duration(seconds: 12));
      if (response.statusCode >= 400) {
        throw HttpException('Update server returned ${response.statusCode}.');
      }

      final json = jsonDecode(response.body) as Map<String, dynamic>;
      if (json['update_available'] == true) {
        await _showUpdate(LocalAppUpdate.fromJson(json));
      } else if (showCurrent && mounted) {
        await _showMessage(
          'You are up to date',
          json['latest_version'] == null
              ? 'No package is currently published for this application.'
              : 'Version ${package.version} is the latest published version.',
          Icons.verified_rounded,
        );
      }
    } on TimeoutException {
      if (showCurrent && mounted) {
        await _showError('The local update server did not respond in time.');
      }
    } catch (error) {
      if (showCurrent && mounted) await _showError(error.toString());
    } finally {
      if (mounted) setState(() => checking = false);
    }
  }

  Future<void> _showUpdate(LocalAppUpdate update) async {
    if (!mounted) return;
    var keepPrompting = true;
    while (mounted && keepPrompting) {
      if (!mounted) return;
      final shouldDownload = await showDialog<bool>(
            context: context,
            barrierDismissible: !update.mandatory,
            builder: (dialogContext) => PopScope(
              canPop: !update.mandatory,
              child: AlertDialog(
                icon: Icon(
                  update.mandatory
                      ? Icons.system_security_update_warning_rounded
                      : Icons.system_update_rounded,
                  color: widget.accentColor ??
                      Theme.of(context).colorScheme.primary,
                  size: 38,
                ),
                title: Text(
                  update.mandatory
                      ? 'Update required'
                      : 'New version available',
                  textAlign: TextAlign.center,
                ),
                content: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 440),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        'TablePlay ${update.version}${update.buildNumber == null ? '' : ' · build ${update.buildNumber}'}',
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontWeight: FontWeight.w900),
                      ),
                      const SizedBox(height: 12),
                      if (update.releaseNotes.isNotEmpty)
                        Container(
                          constraints: const BoxConstraints(maxHeight: 190),
                          padding: const EdgeInsets.all(13),
                          decoration: BoxDecoration(
                            color: Theme.of(
                              context,
                            ).colorScheme.surfaceContainerHighest,
                            borderRadius: BorderRadius.circular(13),
                          ),
                          child: SingleChildScrollView(
                            child: Text(update.releaseNotes),
                          ),
                        ),
                      const SizedBox(height: 12),
                      Text(
                        '${(update.fileSize / 1048576).toStringAsFixed(1)} MB · downloaded from the restaurant server',
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                actionsAlignment: MainAxisAlignment.center,
                actions: [
                  if (!update.mandatory)
                    OutlinedButton(
                      onPressed: () => Navigator.pop(dialogContext, false),
                      child: const Text('Later'),
                    ),
                  FilledButton.icon(
                    onPressed: () => Navigator.pop(dialogContext, true),
                    icon: const Icon(Icons.download_rounded),
                    label: Text(
                      Platform.isWindows
                          ? 'Download and restart'
                          : 'Download update',
                    ),
                  ),
                ],
              ),
            ),
          ) ??
          false;

      if (!shouldDownload) return;
      final launched = await _download(update);
      keepPrompting = update.mandatory && !launched;
    }
  }

  Future<bool> _download(LocalAppUpdate update) async {
    if (!mounted) return false;
    setState(() {
      downloading = true;
      progress = 0;
      progressLabel = 'Downloading TablePlay ${update.version}…';
    });

    File? package;
    IOSink? output;
    try {
      final directory = await getTemporaryDirectory();
      final extension = Platform.isWindows ? 'zip' : 'apk';
      package = File(
        '${directory.path}${Platform.pathSeparator}tableplay-${widget.app}-${update.version}.$extension',
      );
      final request = http.Request('GET', update.downloadUrl)
        ..headers.addAll({
          'Accept': 'application/octet-stream',
          ...widget.headers,
        });
      final response = await request.send().timeout(
            const Duration(seconds: 20),
          );
      if (response.statusCode >= 400) {
        throw HttpException('Download failed (${response.statusCode}).');
      }

      output = package.openWrite();
      var received = 0;
      await for (final chunk in response.stream.timeout(
        const Duration(seconds: 30),
      )) {
        output.add(chunk);
        received += chunk.length;
        if (mounted) {
          setState(() {
            progress = update.fileSize > 0
                ? (received / update.fileSize).clamp(0.0, 1.0)
                : null;
          });
        }
      }
      await output.flush();
      await output.close();
      output = null;

      if (mounted) {
        setState(() {
          progress = null;
          progressLabel = 'Verifying file security…';
        });
      }
      final digest = await sha256.bind(package.openRead()).first;
      if (digest.toString().toLowerCase() != update.sha256) {
        await package.delete();
        throw const FormatException(
          'The downloaded package failed its SHA-256 security check.',
        );
      }

      if (Platform.isAndroid) {
        final result = await OpenFilex.open(
          package.path,
          type: 'application/vnd.android.package-archive',
        );
        if (result.type != ResultType.done) {
          throw FileSystemException(result.message, package.path);
        }
      } else if (Platform.isWindows) {
        await _launchWindowsUpdater(package);
      }
      return true;
    } catch (error) {
      if (mounted) await _showError(error.toString());
      return false;
    } finally {
      await output?.close();
      if (mounted) {
        setState(() {
          downloading = false;
          progress = null;
        });
      }
    }
  }

  Future<void> _launchWindowsUpdater(File package) async {
    final executable = File(Platform.resolvedExecutable);
    final bundledUpdater = File(
      '${executable.parent.path}${Platform.pathSeparator}tableplay_updater.exe',
    );
    if (!await bundledUpdater.exists()) {
      throw const FileSystemException(
        'The TablePlay Windows updater is missing.',
      );
    }

    final temporary = await getTemporaryDirectory();
    final updater = await bundledUpdater.copy(
      '${temporary.path}${Platform.pathSeparator}tableplay_updater_${DateTime.now().millisecondsSinceEpoch}.exe',
    );
    await Process.start(
        updater.path,
        [
          '--package',
          package.path,
          '--target',
          executable.parent.path,
          '--app',
          executable.uri.pathSegments.last,
          '--pid',
          '$pid',
        ],
        mode: ProcessStartMode.detached);
    await Future<void>.delayed(const Duration(milliseconds: 350));
    exit(0);
  }

  Future<void> _showError(String message) => _showMessage(
        'Update could not continue',
        message
            .replaceFirst('Exception: ', '')
            .replaceFirst('HttpException: ', '')
            .replaceFirst('FormatException: ', ''),
        Icons.error_outline_rounded,
      );

  Future<void> _showMessage(String title, String message, IconData icon) async {
    if (!mounted) return;
    final identity = Object();
    noticeIdentity = identity;
    setState(() => notice = _UpdateNotice(title, message, icon));
    await Future<void>.delayed(const Duration(seconds: 5));
    if (mounted && identical(noticeIdentity, identity)) {
      setState(() => notice = null);
    }
  }

  @override
  Widget build(BuildContext context) => _LocalUpdateScope(
        state: this,
        child: Stack(
          fit: StackFit.expand,
          children: [
            widget.child,
            if (notice case final currentNotice?)
              Positioned(
                top: 16,
                left: 16,
                right: 16,
                child: SafeArea(
                  child: Align(
                    alignment: Alignment.topCenter,
                    child: IgnorePointer(
                      child: Semantics(
                        liveRegion: true,
                        label:
                            '${currentNotice.title}. ${currentNotice.message}',
                        child: Card(
                          elevation: 12,
                          color: Theme.of(context).colorScheme.surface,
                          child: ConstrainedBox(
                            constraints: const BoxConstraints(maxWidth: 520),
                            child: Padding(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 18,
                                vertical: 15,
                              ),
                              child: Row(
                                children: [
                                  Icon(
                                    currentNotice.icon,
                                    color: widget.accentColor ??
                                        Theme.of(context).colorScheme.primary,
                                  ),
                                  const SizedBox(width: 13),
                                  Expanded(
                                    child: Column(
                                      mainAxisSize: MainAxisSize.min,
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          currentNotice.title,
                                          style: const TextStyle(
                                            fontWeight: FontWeight.w900,
                                          ),
                                        ),
                                        const SizedBox(height: 3),
                                        Text(currentNotice.message),
                                      ],
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            if (downloading) ...[
              const ModalBarrier(dismissible: false, color: Colors.black54),
              Center(
                child: Card(
                  margin: const EdgeInsets.all(24),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 380),
                    child: Padding(
                      padding: const EdgeInsets.all(26),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.system_update_rounded,
                            size: 40,
                            color: widget.accentColor ??
                                Theme.of(context).colorScheme.primary,
                          ),
                          const SizedBox(height: 17),
                          Text(
                            progressLabel,
                            textAlign: TextAlign.center,
                            style: const TextStyle(fontWeight: FontWeight.w900),
                          ),
                          const SizedBox(height: 16),
                          LinearProgressIndicator(value: progress),
                          if (progress != null) ...[
                            const SizedBox(height: 8),
                            Text('${(progress! * 100).toStringAsFixed(0)}%'),
                          ],
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ],
        ),
      );
}

class _LocalUpdateScope extends InheritedWidget {
  const _LocalUpdateScope({required this.state, required super.child});

  final _LocalUpdateGateState state;

  @override
  bool updateShouldNotify(_LocalUpdateScope oldWidget) => false;
}

class _UpdateNotice {
  const _UpdateNotice(this.title, this.message, this.icon);

  final String title;
  final String message;
  final IconData icon;
}
