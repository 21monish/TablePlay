import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../core/api_client.dart';
import '../theme/tableplay_theme.dart';

enum RunnerObstacleKind { cactus, bird }

class RunnerObstacle {
  RunnerObstacle({
    required this.x,
    required this.width,
    required this.height,
    required this.kind,
    this.flightHeight = 0,
  });

  double x;
  final double width;
  final double height;
  final RunnerObstacleKind kind;
  final double flightHeight;

  double get top =>
      DinosaurRunnerEngine.groundY - height - max(0, flightHeight);

  Rect get hitbox => Rect.fromLTWH(
        x + 4,
        top + 3,
        max(1, width - 8),
        max(1, height - 6),
      );
}

/// Frame-rate-independent game rules used by the widget and unit tests.
class DinosaurRunnerEngine {
  DinosaurRunnerEngine({Random? random}) : random = random ?? Random();

  static const double worldWidth = 1000;
  static const double worldHeight = 420;
  static const double groundY = 326;
  static const double dinosaurX = 112;
  static const double dinosaurWidth = 64;
  static const double dinosaurHeight = 72;
  static const double gravity = 2350;
  static const double jumpVelocity = -850;

  final Random random;
  final List<RunnerObstacle> obstacles = [];

  bool playing = false;
  bool gameOver = false;
  double dinosaurY = groundY - dinosaurHeight;
  double velocityY = 0;
  double elapsedSeconds = 0;
  double speed = 350;
  double spawnCountdown = 0;
  int score = 0;

  bool get isOnGround =>
      dinosaurY >= groundY - dinosaurHeight - 0.5 && velocityY >= 0;

  Rect get dinosaurHitbox => Rect.fromLTWH(
        dinosaurX + 11,
        dinosaurY + 7,
        dinosaurWidth - 19,
        dinosaurHeight - 12,
      );

  void start() {
    playing = true;
    gameOver = false;
    dinosaurY = groundY - dinosaurHeight;
    velocityY = 0;
    elapsedSeconds = 0;
    speed = 350;
    score = 0;
    obstacles
      ..clear()
      ..add(RunnerObstacle(
        x: worldWidth + 135,
        width: 38,
        height: 66,
        kind: RunnerObstacleKind.cactus,
      ));
    spawnCountdown = 2.25;
  }

  bool jump() {
    if (!playing || !isOnGround) return false;
    velocityY = jumpVelocity;
    return true;
  }

  /// Advances the simulation and returns true on a new collision.
  bool update(double deltaSeconds) {
    if (!playing || deltaSeconds <= 0) return false;
    final delta = deltaSeconds.clamp(0.0, 0.05);

    elapsedSeconds += delta;
    speed = min(760, 350 + elapsedSeconds * 7.2);
    score = (elapsedSeconds * 12).floor();

    velocityY += gravity * delta;
    dinosaurY += velocityY * delta;
    final floor = groundY - dinosaurHeight;
    if (dinosaurY >= floor) {
      dinosaurY = floor;
      velocityY = 0;
    }

    for (final obstacle in obstacles) {
      obstacle.x -= speed * delta;
    }
    obstacles.removeWhere((obstacle) => obstacle.x + obstacle.width < -24);

    spawnCountdown -= delta;
    if (spawnCountdown <= 0) {
      _spawnObstacle();
      final difficulty = min(0.48, elapsedSeconds / 100);
      spawnCountdown = 1.35 + random.nextDouble() * 1.05 - difficulty;
    }

    if (obstacles.any((obstacle) => dinosaurHitbox.overlaps(obstacle.hitbox))) {
      playing = false;
      gameOver = true;
      return true;
    }
    return false;
  }

  void _spawnObstacle() {
    final canFly = elapsedSeconds > 12;
    final bird = canFly && random.nextDouble() < 0.26;
    if (bird) {
      obstacles.add(RunnerObstacle(
        x: worldWidth + 45,
        width: 58,
        height: 36,
        kind: RunnerObstacleKind.bird,
        flightHeight: 42,
      ));
      return;
    }

    final tall = random.nextBool();
    obstacles.add(RunnerObstacle(
      x: worldWidth + 45,
      width: tall ? 40 : 52,
      height: tall ? 72 : 50,
      kind: RunnerObstacleKind.cactus,
    ));
  }
}

class DinosaurRunnerV2 extends StatefulWidget {
  const DinosaurRunnerV2({
    super.key,
    required this.game,
    required this.api,
  });

  final Map<String, dynamic> game;
  final ApiClient api;

  @override
  State<DinosaurRunnerV2> createState() => _DinosaurRunnerV2State();
}

class _DinosaurRunnerV2State extends State<DinosaurRunnerV2>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  late final DinosaurRunnerEngine engine;
  late final Ticker ticker;
  final focusNode = FocusNode(debugLabel: 'Dinosaur runner controls');

  Duration previousFrame = Duration.zero;
  SharedPreferences? preferences;
  int highScore = 0;
  bool paused = false;
  bool submitting = false;
  String resultStatus = '';

  String get highScoreKey =>
      'game_high_score_${widget.game['slug'] ?? 'dinosaur-dash'}';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    engine = DinosaurRunnerEngine();
    ticker = createTicker(_onFrame);
    unawaited(_loadHighScore());
  }

  Future<void> _loadHighScore() async {
    try {
      final store = await SharedPreferences.getInstance();
      if (!mounted) return;
      setState(() {
        preferences = store;
        highScore = store.getInt(highScoreKey) ?? 0;
      });
    } catch (_) {
      // A local best score is optional; server result submission remains active.
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!engine.playing) return;
    if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.inactive ||
        state == AppLifecycleState.detached ||
        state == AppLifecycleState.hidden) {
      ticker.stop();
      if (mounted) setState(() => paused = true);
    } else if (state == AppLifecycleState.resumed && paused) {
      previousFrame = Duration.zero;
      ticker.start();
      if (mounted) setState(() => paused = false);
    }
  }

  void _startRun() {
    ticker.stop();
    previousFrame = Duration.zero;
    engine.start();
    paused = false;
    resultStatus = '';
    ticker.start();
    focusNode.requestFocus();
    setState(() {});
  }

  void _primaryAction() {
    if (engine.gameOver || !engine.playing) {
      _startRun();
      return;
    }
    if (engine.jump()) setState(() {});
  }

  void _onFrame(Duration elapsed) {
    if (!engine.playing || paused || !mounted) return;
    if (previousFrame == Duration.zero) {
      previousFrame = elapsed;
      return;
    }
    final delta = (elapsed - previousFrame).inMicroseconds /
        Duration.microsecondsPerSecond;
    previousFrame = elapsed;
    final collided = engine.update(delta);
    setState(() {});
    if (collided) {
      ticker.stop();
      unawaited(_finishRun());
    }
  }

  Future<void> _finishRun() async {
    final finalScore = engine.score;
    if (finalScore > highScore) {
      highScore = finalScore;
      await preferences?.setInt(highScoreKey, finalScore);
      if (mounted) setState(() {});
    }

    if (submitting) return;
    submitting = true;
    if (mounted) setState(() => resultStatus = 'Saving score...');
    try {
      await widget.api.post('/games/results', {
        'game_id': widget.game['id'],
        'player_name': 'Table player',
        'player_position': 1,
        'score': finalScore,
        'duration_seconds': engine.elapsedSeconds.round(),
      });
      if (mounted) setState(() => resultStatus = 'Score saved');
    } catch (_) {
      if (mounted) {
        setState(() => resultStatus =
            'Score not saved - check the game session connection');
      }
    } finally {
      submitting = false;
    }
  }

  KeyEventResult _handleKey(FocusNode node, KeyEvent event) {
    if (event is! KeyDownEvent) return KeyEventResult.ignored;
    if (event.logicalKey == LogicalKeyboardKey.space ||
        event.logicalKey == LogicalKeyboardKey.arrowUp) {
      _primaryAction();
      return KeyEventResult.handled;
    }
    return KeyEventResult.ignored;
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    ticker.dispose();
    focusNode.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final displayBest = max(highScore, engine.score);
    return Scaffold(
      appBar: AppBar(
        title: Text('${widget.game['name'] ?? 'Dinosaur Dash'}'),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 18),
            child: Center(
              child: Text(
                'BEST ${displayBest.toString().padLeft(5, '0')}',
                style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w900,
                  letterSpacing: 1,
                ),
              ),
            ),
          ),
        ],
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(18),
          child: Column(
            children: [
              Row(
                children: [
                  Expanded(
                    child: _RunnerStat(
                      label: 'SCORE',
                      value: engine.score.toString().padLeft(5, '0'),
                      icon: Icons.stars_rounded,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _RunnerStat(
                      label: 'BEST',
                      value: displayBest.toString().padLeft(5, '0'),
                      icon: Icons.emoji_events_rounded,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _RunnerStat(
                      label: 'SPEED',
                      value: '${(engine.speed / 350).toStringAsFixed(1)}x',
                      icon: Icons.speed_rounded,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              Focus(
                autofocus: true,
                focusNode: focusNode,
                onKeyEvent: _handleKey,
                child: GestureDetector(
                  key: const Key('dinosaur-arena'),
                  behavior: HitTestBehavior.opaque,
                  onTap: _primaryAction,
                  child: Container(
                    clipBehavior: Clip.antiAlias,
                    decoration: BoxDecoration(
                      color: const Color(0xffdff4ec),
                      borderRadius: BorderRadius.circular(22),
                      border: Border.all(color: TablePlayColors.border),
                      boxShadow: [
                        BoxShadow(
                          color: TablePlayColors.deep.withValues(alpha: .1),
                          blurRadius: 22,
                          offset: const Offset(0, 10),
                        ),
                      ],
                    ),
                    child: AspectRatio(
                      aspectRatio: DinosaurRunnerEngine.worldWidth /
                          DinosaurRunnerEngine.worldHeight,
                      child: Stack(
                        fit: StackFit.expand,
                        children: [
                          CustomPaint(
                            painter: _DinosaurRunnerPainter(engine),
                          ),
                          if (!engine.playing || paused)
                            ColoredBox(
                              color:
                                  TablePlayColors.deeper.withValues(alpha: .56),
                              child: Center(
                                child: Container(
                                  constraints:
                                      const BoxConstraints(maxWidth: 390),
                                  margin: const EdgeInsets.all(20),
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 24,
                                    vertical: 20,
                                  ),
                                  decoration: BoxDecoration(
                                    color: Colors.white.withValues(alpha: .96),
                                    borderRadius: BorderRadius.circular(20),
                                  ),
                                  child: Column(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Icon(
                                        paused
                                            ? Icons.pause_circle_rounded
                                            : engine.gameOver
                                                ? Icons.flag_rounded
                                                : Icons.directions_run_rounded,
                                        color: engine.gameOver
                                            ? TablePlayColors.accent
                                            : TablePlayColors.deep,
                                        size: 38,
                                      ),
                                      const SizedBox(height: 8),
                                      Text(
                                        paused
                                            ? 'Paused'
                                            : engine.gameOver
                                                ? 'Game over'
                                                : 'Ready to run?',
                                        textAlign: TextAlign.center,
                                        style: const TextStyle(
                                          fontSize: 22,
                                          fontWeight: FontWeight.w900,
                                        ),
                                      ),
                                      const SizedBox(height: 5),
                                      Text(
                                        paused
                                            ? 'Return to TablePlay to continue.'
                                            : engine.gameOver
                                                ? 'Score ${engine.score.toString().padLeft(5, '0')} - tap to race again.'
                                                : 'Jump over every obstacle and keep the table record.',
                                        textAlign: TextAlign.center,
                                        style: const TextStyle(
                                          color: TablePlayColors.muted,
                                          fontSize: 12,
                                          height: 1.35,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 14),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: TablePlayColors.border),
                ),
                child: Row(
                  children: [
                    Container(
                      width: 40,
                      height: 40,
                      decoration: BoxDecoration(
                        color: TablePlayColors.gold.withValues(alpha: .22),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Icon(
                        Icons.keyboard_double_arrow_up_rounded,
                        color: TablePlayColors.deep,
                      ),
                    ),
                    const SizedBox(width: 12),
                    const Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Tap, Space, or Arrow Up',
                            style: TextStyle(fontWeight: FontWeight.w900),
                          ),
                          SizedBox(height: 2),
                          Text(
                            'Time each jump carefully. Speed rises while you survive.',
                            style: TextStyle(
                              color: TablePlayColors.muted,
                              fontSize: 11,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 10),
                    FilledButton.icon(
                      key: Key(
                          engine.playing ? 'dinosaur-jump' : 'dinosaur-start'),
                      onPressed: paused ? null : _primaryAction,
                      icon: Icon(engine.playing
                          ? Icons.arrow_upward_rounded
                          : Icons.play_arrow_rounded),
                      label: Text(engine.gameOver
                          ? 'Play again'
                          : engine.playing
                              ? 'Jump'
                              : 'Start run'),
                    ),
                  ],
                ),
              ),
              if (resultStatus.isNotEmpty) ...[
                const SizedBox(height: 10),
                Text(
                  resultStatus,
                  key: const Key('dinosaur-result-status'),
                  style: TextStyle(
                    color: resultStatus == 'Score saved'
                        ? TablePlayColors.success
                        : TablePlayColors.muted,
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _RunnerStat extends StatelessWidget {
  const _RunnerStat({
    required this.label,
    required this.value,
    required this.icon,
  });

  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(15),
          border: Border.all(color: TablePlayColors.border),
        ),
        child: Row(
          children: [
            Icon(icon, size: 20, color: TablePlayColors.accent),
            const SizedBox(width: 8),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    label,
                    style: const TextStyle(
                      color: TablePlayColors.muted,
                      fontSize: 8,
                      fontWeight: FontWeight.w900,
                      letterSpacing: .8,
                    ),
                  ),
                  Text(
                    value,
                    maxLines: 1,
                    style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      );
}

class _DinosaurRunnerPainter extends CustomPainter {
  const _DinosaurRunnerPainter(this.engine);

  final DinosaurRunnerEngine engine;

  @override
  void paint(Canvas canvas, Size size) {
    final sx = size.width / DinosaurRunnerEngine.worldWidth;
    final sy = size.height / DinosaurRunnerEngine.worldHeight;
    Rect rect(double x, double y, double width, double height) =>
        Rect.fromLTWH(x * sx, y * sy, width * sx, height * sy);
    Offset point(double x, double y) => Offset(x * sx, y * sy);

    final full = Offset.zero & size;
    canvas.drawRect(
      full,
      Paint()
        ..shader = const LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Color(0xffccefe7), Color(0xfff4f0cf)],
        ).createShader(full),
    );

    canvas.drawCircle(
      point(858, 78),
      35 * min(sx, sy),
      Paint()..color = const Color(0xffffcf72),
    );
    _cloud(canvas, point(176, 83), sx, sy, .9);
    _cloud(canvas, point(520, 108), sx, sy, .65);

    final hillPaint = Paint()..color = const Color(0xff9fceb4);
    final hills = Path()
      ..moveTo(0, 277 * sy)
      ..quadraticBezierTo(150 * sx, 188 * sy, 318 * sx, 277 * sy)
      ..quadraticBezierTo(490 * sx, 166 * sy, 664 * sx, 277 * sy)
      ..quadraticBezierTo(838 * sx, 203 * sy, size.width, 277 * sy)
      ..lineTo(size.width, DinosaurRunnerEngine.groundY * sy)
      ..lineTo(0, DinosaurRunnerEngine.groundY * sy)
      ..close();
    canvas.drawPath(hills, hillPaint);

    canvas.drawRect(
      rect(
        0,
        DinosaurRunnerEngine.groundY,
        DinosaurRunnerEngine.worldWidth,
        DinosaurRunnerEngine.worldHeight - DinosaurRunnerEngine.groundY,
      ),
      Paint()..color = const Color(0xffecd8a6),
    );
    canvas.drawRect(
      rect(0, DinosaurRunnerEngine.groundY, DinosaurRunnerEngine.worldWidth, 7),
      Paint()..color = TablePlayColors.deep,
    );

    final groundOffset = (engine.elapsedSeconds * engine.speed * .55) % 74;
    final markPaint = Paint()
      ..color = const Color(0xffb99f70)
      ..strokeWidth = max(1, 3 * sy)
      ..strokeCap = StrokeCap.round;
    for (double x = -groundOffset; x < 1060; x += 74) {
      canvas.drawLine(
        point(x, 355),
        point(x + 27, 355),
        markPaint,
      );
    }

    for (final obstacle in engine.obstacles) {
      if (obstacle.kind == RunnerObstacleKind.bird) {
        _bird(canvas, obstacle, sx, sy);
      } else {
        _cactus(canvas, obstacle, sx, sy);
      }
    }
    _dinosaur(canvas, sx, sy);
  }

  void _cloud(
      Canvas canvas, Offset center, double sx, double sy, double scale) {
    final paint = Paint()..color = Colors.white.withValues(alpha: .72);
    canvas.drawOval(
        Rect.fromCenter(
          center: center,
          width: 110 * sx * scale,
          height: 34 * sy * scale,
        ),
        paint);
    canvas.drawCircle(center.translate(-24 * sx * scale, -12 * sy * scale),
        22 * min(sx, sy) * scale, paint);
    canvas.drawCircle(center.translate(13 * sx * scale, -16 * sy * scale),
        29 * min(sx, sy) * scale, paint);
  }

  void _dinosaur(Canvas canvas, double sx, double sy) {
    Rect r(double x, double y, double width, double height) => Rect.fromLTWH(
          x * sx,
          y * sy,
          width * sx,
          height * sy,
        );
    final x = DinosaurRunnerEngine.dinosaurX;
    final y = engine.dinosaurY;
    final body = Paint()..color = TablePlayColors.deep;
    final highlight = Paint()..color = const Color(0xff2a6f60);

    final tail = Path()
      ..moveTo((x + 13) * sx, (y + 42) * sy)
      ..lineTo((x - 24) * sx, (y + 28) * sy)
      ..lineTo((x + 8) * sx, (y + 55) * sy)
      ..close();
    canvas.drawPath(tail, body);
    canvas.drawRRect(
      RRect.fromRectAndRadius(
          r(x + 6, y + 25, 43, 39), Radius.circular(11 * min(sx, sy))),
      body,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
          r(x + 34, y + 2, 31, 35), Radius.circular(8 * min(sx, sy))),
      highlight,
    );
    canvas.drawRect(r(x + 42, y + 26, 10, 24), highlight);
    canvas.drawCircle(
      Offset((x + 56) * sx, (y + 12) * sy),
      3.4 * min(sx, sy),
      Paint()..color = Colors.white,
    );
    canvas.drawCircle(
      Offset((x + 57) * sx, (y + 12) * sy),
      1.5 * min(sx, sy),
      Paint()..color = TablePlayColors.deeper,
    );

    final runningPhase = (engine.elapsedSeconds * 10).floor().isEven;
    final legA = runningPhase && engine.isOnGround ? 6.0 : 0.0;
    final legB = runningPhase && engine.isOnGround ? 0.0 : 6.0;
    canvas.drawRRect(
      RRect.fromRectAndRadius(
          r(x + 13, y + 57, 10, 15 + legA), Radius.circular(3 * sx)),
      body,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
          r(x + 36, y + 57, 10, 15 + legB), Radius.circular(3 * sx)),
      body,
    );

    if (engine.playing && engine.isOnGround) {
      canvas.drawCircle(
        Offset((x - 5) * sx, (DinosaurRunnerEngine.groundY + 7) * sy),
        4 * min(sx, sy),
        Paint()..color = const Color(0x55a58d63),
      );
    }
  }

  void _cactus(Canvas canvas, RunnerObstacle obstacle, double sx, double sy) {
    Rect r(double x, double y, double width, double height) => Rect.fromLTWH(
          x * sx,
          y * sy,
          width * sx,
          height * sy,
        );
    final paint = Paint()..color = const Color(0xff2f815c);
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        r(obstacle.x + obstacle.width * .36, obstacle.top, obstacle.width * .32,
            obstacle.height),
        Radius.circular(6 * min(sx, sy)),
      ),
      paint,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        r(obstacle.x, obstacle.top + obstacle.height * .36,
            obstacle.width * .48, obstacle.height * .18),
        Radius.circular(5 * min(sx, sy)),
      ),
      paint,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        r(
            obstacle.x + obstacle.width * .58,
            obstacle.top + obstacle.height * .52,
            obstacle.width * .42,
            obstacle.height * .17),
        Radius.circular(5 * min(sx, sy)),
      ),
      paint,
    );
  }

  void _bird(Canvas canvas, RunnerObstacle obstacle, double sx, double sy) {
    final center = Offset(
      (obstacle.x + obstacle.width / 2) * sx,
      (obstacle.top + obstacle.height / 2) * sy,
    );
    final paint = Paint()
      ..color = TablePlayColors.accent
      ..style = PaintingStyle.stroke
      ..strokeWidth = max(2, 7 * min(sx, sy))
      ..strokeCap = StrokeCap.round;
    final wingsUp = (engine.elapsedSeconds * 11).floor().isEven;
    final lift = wingsUp ? -13.0 : 7.0;
    final path = Path()
      ..moveTo((obstacle.x + 3) * sx, center.dy)
      ..quadraticBezierTo((obstacle.x + obstacle.width * .28) * sx,
          (obstacle.top + lift) * sy, center.dx, center.dy)
      ..quadraticBezierTo(
          (obstacle.x + obstacle.width * .73) * sx,
          (obstacle.top + lift) * sy,
          (obstacle.x + obstacle.width - 3) * sx,
          center.dy);
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant _DinosaurRunnerPainter oldDelegate) => true;
}
