import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../core/api_client.dart';
import '../theme/tableplay_theme.dart';

enum BalloonPowerKind { magnet, slow, shield }

class SkyBarrier {
  SkyBarrier({
    required this.y,
    required this.gapCenter,
    required this.gapWidth,
  });

  double y;
  final double gapCenter;
  final double gapWidth;

  static const double thickness = 28;

  Rect get leftHitbox => Rect.fromLTWH(
        0,
        y + 3,
        max(0, gapCenter - gapWidth / 2),
        thickness - 6,
      );

  Rect get rightHitbox {
    final start = gapCenter + gapWidth / 2;
    return Rect.fromLTWH(
      start,
      y + 3,
      max(0, BalloonAscentEngine.worldWidth - start),
      thickness - 6,
    );
  }
}

class SkyBubble {
  SkyBubble({required this.x, required this.y});

  double x;
  double y;

  static const double radius = 12;
  Rect get hitbox => Rect.fromCircle(center: Offset(x, y), radius: radius);
}

class SkyPowerUp {
  SkyPowerUp({required this.x, required this.y, required this.kind});

  double x;
  double y;
  final BalloonPowerKind kind;

  Rect get hitbox => Rect.fromCircle(center: Offset(x, y), radius: 18);
}

/// Frame-rate-independent rules for TablePlay's original balloon game.
class BalloonAscentEngine {
  BalloonAscentEngine({Random? random}) : random = random ?? Random();

  static const double worldWidth = 720;
  static const double worldHeight = 460;
  static const double balloonWidth = 54;
  static const double balloonHeight = 72;
  static const double balloonY = 294;

  final Random random;
  final List<SkyBarrier> barriers = [];
  final List<SkyBubble> bubbles = [];
  final List<SkyPowerUp> powerUps = [];

  bool playing = false;
  bool gameOver = false;
  double balloonX = worldWidth / 2;
  double targetX = worldWidth / 2;
  double elapsedSeconds = 0;
  double worldSpeed = 118;
  double spawnCountdown = 0;
  double lastGapCenter = worldWidth / 2;
  double magnetRemaining = 0;
  double slowRemaining = 0;
  double shieldRemaining = 0;
  int bubbleScore = 0;
  int score = 0;
  int bubblesCollected = 0;

  Rect get balloonHitbox => Rect.fromLTWH(
        balloonX - 18,
        balloonY + 8,
        36,
        52,
      );

  bool get magnetActive => magnetRemaining > 0;
  bool get slowActive => slowRemaining > 0;
  bool get shieldActive => shieldRemaining > 0;

  void start() {
    playing = true;
    gameOver = false;
    balloonX = worldWidth / 2;
    targetX = balloonX;
    elapsedSeconds = 0;
    worldSpeed = 118;
    spawnCountdown = .65;
    lastGapCenter = worldWidth / 2;
    magnetRemaining = 0;
    slowRemaining = 0;
    shieldRemaining = 0;
    bubbleScore = 0;
    score = 0;
    bubblesCollected = 0;
    barriers.clear();
    bubbles.clear();
    powerUps.clear();
  }

  void moveTo(double worldX) {
    targetX = worldX.clamp(
      balloonWidth / 2,
      worldWidth - balloonWidth / 2,
    );
  }

  void nudge(double amount) => moveTo(targetX + amount);

  /// Advances the game and returns true when a new fatal collision occurs.
  bool update(double deltaSeconds) {
    if (!playing || deltaSeconds <= 0) return false;
    final delta = deltaSeconds.clamp(0.0, 0.05);

    elapsedSeconds += delta;
    magnetRemaining = max(0, magnetRemaining - delta);
    slowRemaining = max(0, slowRemaining - delta);
    shieldRemaining = max(0, shieldRemaining - delta);

    final survivalScore = (elapsedSeconds * 3).floor();
    score = survivalScore + bubbleScore;
    final normalSpeed = min(300.0, 118 + elapsedSeconds * 1.25 + score * .16);
    worldSpeed = slowActive ? normalSpeed * .55 : normalSpeed;

    final movementBlend = min(1.0, delta * 8.5);
    balloonX += (targetX - balloonX) * movementBlend;

    for (final barrier in barriers) {
      barrier.y += worldSpeed * delta;
    }
    for (final bubble in bubbles) {
      bubble.y += worldSpeed * delta;
      if (magnetActive) {
        final dx = balloonX - bubble.x;
        final dy = (balloonY + balloonHeight / 2) - bubble.y;
        final distance = sqrt(dx * dx + dy * dy);
        if (distance < 210) {
          final pull = min(1.0, delta * 4.8);
          bubble.x += dx * pull;
          bubble.y += dy * pull;
        }
      }
    }
    for (final power in powerUps) {
      power.y += worldSpeed * delta;
    }

    spawnCountdown -= delta;
    if (spawnCountdown <= 0) {
      _spawnRow();
      final interval = max(1.05, 1.92 - elapsedSeconds / 150);
      spawnCountdown = interval + random.nextDouble() * .28;
    }

    for (final bubble in List<SkyBubble>.from(bubbles)) {
      if (balloonHitbox.overlaps(bubble.hitbox)) {
        bubbles.remove(bubble);
        bubblesCollected++;
        bubbleScore += 10;
        score = (elapsedSeconds * 3).floor() + bubbleScore;
      }
    }

    for (final power in List<SkyPowerUp>.from(powerUps)) {
      if (!balloonHitbox.overlaps(power.hitbox)) continue;
      powerUps.remove(power);
      switch (power.kind) {
        case BalloonPowerKind.magnet:
          magnetRemaining = max(magnetRemaining, 15);
        case BalloonPowerKind.slow:
          slowRemaining = max(slowRemaining, 7);
        case BalloonPowerKind.shield:
          shieldRemaining = max(shieldRemaining, 15);
      }
    }

    final collided = barriers.any(
      (barrier) =>
          balloonHitbox.overlaps(barrier.leftHitbox) ||
          balloonHitbox.overlaps(barrier.rightHitbox),
    );
    if (collided && !shieldActive) {
      playing = false;
      gameOver = true;
      return true;
    }

    barriers.removeWhere((barrier) => barrier.y > worldHeight + 50);
    bubbles.removeWhere((bubble) => bubble.y > worldHeight + 30);
    powerUps.removeWhere((power) => power.y > worldHeight + 35);
    return false;
  }

  void _spawnRow() {
    final difficulty = min(42.0, elapsedSeconds / 2.6);
    final gapWidth = 190 - difficulty;
    final safeMin = gapWidth / 2 + 20;
    final safeMax = worldWidth - gapWidth / 2 - 20;
    final shift = (random.nextDouble() * 250) - 125;
    var nextGap = (lastGapCenter + shift).clamp(safeMin, safeMax);
    if ((nextGap - lastGapCenter).abs() < 48) {
      nextGap =
          (nextGap + (random.nextBool() ? 72 : -72)).clamp(safeMin, safeMax);
    }
    lastGapCenter = nextGap;

    const rowY = -42.0;
    barriers.add(SkyBarrier(
      y: rowY,
      gapCenter: nextGap,
      gapWidth: gapWidth,
    ));

    final bubbleCount = 1 + random.nextInt(3);
    for (var index = 0; index < bubbleCount; index++) {
      final offset = (index - (bubbleCount - 1) / 2) * 31;
      bubbles.add(SkyBubble(
        x: (nextGap + offset).clamp(22, worldWidth - 22),
        y: rowY - 45 - index * 30,
      ));
    }

    if (elapsedSeconds > 5 && random.nextDouble() < .2) {
      powerUps.add(SkyPowerUp(
        x: nextGap,
        y: rowY - 95,
        kind: BalloonPowerKind.values[random.nextInt(3)],
      ));
    }
  }
}

class BalloonAscentV2 extends StatefulWidget {
  const BalloonAscentV2({
    super.key,
    required this.game,
    required this.api,
  });

  final Map<String, dynamic> game;
  final ApiClient api;

  @override
  State<BalloonAscentV2> createState() => _BalloonAscentV2State();
}

class _BalloonAscentV2State extends State<BalloonAscentV2>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  late final BalloonAscentEngine engine;
  late final Ticker ticker;
  final focusNode = FocusNode(debugLabel: 'Balloon ascent controls');

  Duration previousFrame = Duration.zero;
  SharedPreferences? preferences;
  int highScore = 0;
  bool paused = false;
  bool submitting = false;
  String resultStatus = '';

  String get highScoreKey =>
      'game_high_score_${widget.game['slug'] ?? 'balloon-ascent'}';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    engine = BalloonAscentEngine();
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
      // Local score storage is optional; server result submission still works.
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
    if (event.logicalKey == LogicalKeyboardKey.arrowLeft) {
      engine.nudge(-72);
      return KeyEventResult.handled;
    }
    if (event.logicalKey == LogicalKeyboardKey.arrowRight) {
      engine.nudge(72);
      return KeyEventResult.handled;
    }
    if (event.logicalKey == LogicalKeyboardKey.space && !engine.playing) {
      _startRun();
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
        title: Text('${widget.game['name'] ?? 'Balloon Ascent'}'),
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
          padding: const EdgeInsets.all(16),
          child: Column(
            children: [
              Row(
                children: [
                  Expanded(
                    child: _BalloonStat(
                      label: 'SCORE',
                      value: engine.score.toString().padLeft(5, '0'),
                      icon: Icons.stars_rounded,
                    ),
                  ),
                  const SizedBox(width: 9),
                  Expanded(
                    child: _BalloonStat(
                      label: 'BUBBLES',
                      value: '${engine.bubblesCollected}',
                      icon: Icons.bubble_chart_rounded,
                    ),
                  ),
                  const SizedBox(width: 9),
                  Expanded(
                    child: _BalloonStat(
                      label: 'SPEED',
                      value:
                          '${(engine.worldSpeed / 118).clamp(1, 9).toStringAsFixed(1)}x',
                      icon: Icons.speed_rounded,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 11),
              Wrap(
                alignment: WrapAlignment.center,
                spacing: 8,
                runSpacing: 7,
                children: [
                  _PowerPill(
                    label: 'MAGNET',
                    seconds: engine.magnetRemaining,
                    icon: Icons.attractions_rounded,
                    color: const Color(0xff8b5cf6),
                  ),
                  _PowerPill(
                    label: 'SLOW',
                    seconds: engine.slowRemaining,
                    icon: Icons.hourglass_bottom_rounded,
                    color: const Color(0xff0ea5e9),
                  ),
                  _PowerPill(
                    label: 'SHIELD',
                    seconds: engine.shieldRemaining,
                    icon: Icons.shield_rounded,
                    color: const Color(0xff10b981),
                  ),
                ],
              ),
              const SizedBox(height: 11),
              Focus(
                focusNode: focusNode,
                onKeyEvent: _handleKey,
                child: LayoutBuilder(
                  builder: (context, constraints) => GestureDetector(
                    behavior: HitTestBehavior.opaque,
                    onHorizontalDragStart: engine.playing
                        ? (details) => engine.moveTo(
                              details.localPosition.dx /
                                  constraints.maxWidth *
                                  BalloonAscentEngine.worldWidth,
                            )
                        : null,
                    onHorizontalDragUpdate: engine.playing
                        ? (details) => engine.moveTo(
                              details.localPosition.dx /
                                  constraints.maxWidth *
                                  BalloonAscentEngine.worldWidth,
                            )
                        : null,
                    child: AspectRatio(
                      aspectRatio: BalloonAscentEngine.worldWidth /
                          BalloonAscentEngine.worldHeight,
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(24),
                        child: Stack(
                          fit: StackFit.expand,
                          children: [
                            CustomPaint(
                              key: const Key('balloon-game-canvas'),
                              painter: _BalloonPainter(engine),
                            ),
                            if (!engine.playing || paused)
                              Container(
                                color: const Color(0xb51b1645),
                                padding: const EdgeInsets.all(22),
                                child: Center(
                                  child: Column(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Icon(
                                        engine.gameOver
                                            ? Icons.air_rounded
                                            : Icons.flight_rounded,
                                        color: Colors.white,
                                        size: 46,
                                      ),
                                      const SizedBox(height: 9),
                                      Text(
                                        paused
                                            ? 'Flight paused'
                                            : (engine.gameOver
                                                ? 'Flight over - ${engine.score} points'
                                                : 'Guide the balloon through the sky'),
                                        textAlign: TextAlign.center,
                                        style: const TextStyle(
                                          color: Colors.white,
                                          fontSize: 20,
                                          fontWeight: FontWeight.w900,
                                        ),
                                      ),
                                      const SizedBox(height: 6),
                                      const Text(
                                        'Drag left or right. Collect bubbles and power-ups. Avoid the spikes.',
                                        textAlign: TextAlign.center,
                                        style: TextStyle(
                                          color: Colors.white70,
                                          fontSize: 12,
                                        ),
                                      ),
                                      if (!paused) ...[
                                        const SizedBox(height: 15),
                                        FilledButton.icon(
                                          key: const Key('balloon-start'),
                                          onPressed: _startRun,
                                          icon: const Icon(
                                              Icons.play_arrow_rounded),
                                          label: Text(engine.gameOver
                                              ? 'Fly again'
                                              : 'Start flight'),
                                        ),
                                      ],
                                    ],
                                  ),
                                ),
                              ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 9),
              Text(
                resultStatus.isEmpty
                    ? 'Every bubble adds 10 points. Magnet and shield last 15 seconds; slowdown lasts 7 seconds.'
                    : resultStatus,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  color: TablePlayColors.muted,
                  fontSize: 11,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _BalloonStat extends StatelessWidget {
  const _BalloonStat({
    required this.label,
    required this.value,
    required this.icon,
  });

  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: TablePlayColors.border),
        ),
        child: Row(
          children: [
            Icon(icon, color: TablePlayColors.accent, size: 20),
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
                    style: const TextStyle(
                      fontSize: 16,
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

class _PowerPill extends StatelessWidget {
  const _PowerPill({
    required this.label,
    required this.seconds,
    required this.icon,
    required this.color,
  });

  final String label;
  final double seconds;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final active = seconds > 0;
    return AnimatedContainer(
      duration: const Duration(milliseconds: 180),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: active ? color.withValues(alpha: .14) : Colors.white,
        borderRadius: BorderRadius.circular(40),
        border: Border.all(
          color: active ? color : TablePlayColors.border,
        ),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: active ? color : TablePlayColors.muted),
          const SizedBox(width: 5),
          Text(
            active ? '$label ${seconds.ceil()}s' : label,
            style: TextStyle(
              color: active ? color : TablePlayColors.muted,
              fontSize: 9,
              fontWeight: FontWeight.w900,
            ),
          ),
        ],
      ),
    );
  }
}

class _BalloonPainter extends CustomPainter {
  const _BalloonPainter(this.engine);

  final BalloonAscentEngine engine;

  @override
  void paint(Canvas canvas, Size size) {
    final sx = size.width / BalloonAscentEngine.worldWidth;
    final sy = size.height / BalloonAscentEngine.worldHeight;
    Rect rect(double x, double y, double width, double height) =>
        Rect.fromLTWH(x * sx, y * sy, width * sx, height * sy);
    Offset point(double x, double y) => Offset(x * sx, y * sy);

    final sky = Paint()
      ..shader = const LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: [Color(0xff12204d), Color(0xff455fbc), Color(0xffa5dcff)],
      ).createShader(Offset.zero & size);
    canvas.drawRect(Offset.zero & size, sky);

    final starPaint = Paint()..color = Colors.white.withValues(alpha: .45);
    for (var index = 0; index < 18; index++) {
      final x = ((index * 97 + 31) % 720) * sx;
      final y = ((index * 47 + engine.elapsedSeconds * 12) % 205) * sy;
      canvas.drawCircle(Offset(x, y), (index.isEven ? 1.5 : 1) * sx, starPaint);
    }

    final cloudPaint = Paint()..color = Colors.white.withValues(alpha: .2);
    for (var index = 0; index < 5; index++) {
      final y = ((index * 117 + engine.elapsedSeconds * 23) % 570 - 80) * sy;
      final x = (55 + index * 151) * sx;
      canvas.drawOval(
          Rect.fromCenter(
              center: Offset(x, y), width: 98 * sx, height: 27 * sy),
          cloudPaint);
      canvas.drawCircle(Offset(x - 23 * sx, y - 8 * sy), 19 * sx, cloudPaint);
      canvas.drawCircle(Offset(x + 13 * sx, y - 11 * sy), 24 * sx, cloudPaint);
    }

    for (final barrier in engine.barriers) {
      final left = barrier.leftHitbox;
      final right = barrier.rightHitbox;
      _drawSpikePlatform(
          canvas, rect(left.left, left.top, left.width, left.height), sx, sy);
      _drawSpikePlatform(canvas,
          rect(right.left, right.top, right.width, right.height), sx, sy);
    }

    for (final bubble in engine.bubbles) {
      final center = point(bubble.x, bubble.y);
      final radius = SkyBubble.radius * sx;
      final paint = Paint()
        ..shader = RadialGradient(
          colors: [
            Colors.white,
            const Color(0xff55d6ff).withValues(alpha: .55)
          ],
        ).createShader(Rect.fromCircle(center: center, radius: radius));
      canvas.drawCircle(center, radius, paint);
      canvas.drawCircle(
        center,
        radius,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2 * sx
          ..color = Colors.white.withValues(alpha: .85),
      );
      canvas.drawCircle(
        center.translate(-4 * sx, -4 * sy),
        2.5 * sx,
        Paint()..color = Colors.white,
      );
    }

    for (final power in engine.powerUps) {
      final color = switch (power.kind) {
        BalloonPowerKind.magnet => const Color(0xffa78bfa),
        BalloonPowerKind.slow => const Color(0xff38bdf8),
        BalloonPowerKind.shield => const Color(0xff34d399),
      };
      final label = switch (power.kind) {
        BalloonPowerKind.magnet => 'M',
        BalloonPowerKind.slow => 'S',
        BalloonPowerKind.shield => 'H',
      };
      final center = point(power.x, power.y);
      canvas.drawCircle(
          center, 20 * sx, Paint()..color = color.withValues(alpha: .3));
      canvas.drawCircle(center, 15 * sx, Paint()..color = color);
      final text = TextPainter(
        text: TextSpan(
          text: label,
          style: TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.w900,
            fontSize: 13 * sx,
          ),
        ),
        textDirection: TextDirection.ltr,
      )..layout();
      text.paint(canvas, center - Offset(text.width / 2, text.height / 2));
    }

    _drawBalloon(canvas, sx, sy);
  }

  void _drawSpikePlatform(Canvas canvas, Rect platform, double sx, double sy) {
    if (platform.width <= 0) return;
    final base = Paint()..color = const Color(0xff302454);
    canvas.drawRRect(
      RRect.fromRectAndRadius(platform, Radius.circular(4 * sx)),
      base,
    );
    final spikePaint = Paint()..color = const Color(0xffff7b54);
    final spikeWidth = 18 * sx;
    for (double x = platform.left; x < platform.right; x += spikeWidth) {
      final path = Path()
        ..moveTo(x, platform.top)
        ..lineTo(
            min(x + spikeWidth / 2, platform.right), platform.top - 13 * sy)
        ..lineTo(min(x + spikeWidth, platform.right), platform.top)
        ..close();
      canvas.drawPath(path, spikePaint);
    }
    canvas.drawLine(
      platform.topLeft,
      platform.topRight,
      Paint()
        ..color = Colors.white.withValues(alpha: .25)
        ..strokeWidth = 2 * sy,
    );
  }

  void _drawBalloon(Canvas canvas, double sx, double sy) {
    final x = engine.balloonX;
    final y = BalloonAscentEngine.balloonY;
    Offset point(double px, double py) => Offset(px * sx, py * sy);
    final center = Offset(x * sx, (y + 25) * sy);
    if (engine.shieldActive) {
      canvas.drawCircle(
        center.translate(0, 9 * sy),
        43 * sx,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 5 * sx
          ..color = const Color(0xff5eead4).withValues(alpha: .85),
      );
    }
    final balloonRect = Rect.fromCenter(
      center: center,
      width: 52 * sx,
      height: 62 * sy,
    );
    final balloonPaint = Paint()
      ..shader = const LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: [Color(0xffffcb45), Color(0xffff6b3d), Color(0xffe63f63)],
      ).createShader(balloonRect);
    canvas.drawOval(balloonRect, balloonPaint);
    canvas.drawArc(
      balloonRect.deflate(7 * sx),
      -pi / 2,
      pi,
      false,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3 * sx
        ..color = Colors.white.withValues(alpha: .5),
    );
    final rope = Paint()
      ..color = const Color(0xff513a35)
      ..strokeWidth = 1.5 * sx;
    canvas.drawLine(point(x - 13, y + 51), point(x - 9, y + 65), rope);
    canvas.drawLine(point(x + 13, y + 51), point(x + 9, y + 65), rope);
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromCenter(
          center: point(x, y + 68),
          width: 23 * sx,
          height: 14 * sy,
        ),
        Radius.circular(3 * sx),
      ),
      Paint()..color = const Color(0xff8b5a3c),
    );
    if (engine.magnetActive) {
      canvas.drawCircle(
        point(x, y + 34),
        65 * sx,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.5 * sx
          ..color = const Color(0xffc4b5fd).withValues(alpha: .45),
      );
    }
  }

  @override
  bool shouldRepaint(covariant _BalloonPainter oldDelegate) => true;
}
