import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tableplay_tablet/core/api_client.dart';
import 'package:tableplay_tablet/features/balloon_ascent.dart';

void main() {
  test('balloon movement is smooth and remains inside the world', () {
    final engine = BalloonAscentEngine(random: Random(4))..start();
    final initialX = engine.balloonX;

    engine.moveTo(BalloonAscentEngine.worldWidth);
    engine.update(.05);

    expect(engine.balloonX, greaterThan(initialX));
    expect(engine.balloonX,
        lessThanOrEqualTo(BalloonAscentEngine.worldWidth - 27));
    expect(engine.targetX,
        BalloonAscentEngine.worldWidth - BalloonAscentEngine.balloonWidth / 2);
  });

  test('a collected bubble adds ten points', () {
    final engine = BalloonAscentEngine(random: Random(5))..start();
    engine.bubbles.add(SkyBubble(
      x: engine.balloonX,
      y: BalloonAscentEngine.balloonY + 28,
    ));

    engine.update(.01);

    expect(engine.bubblesCollected, 1);
    expect(engine.bubbleScore, 10);
    expect(engine.score, greaterThanOrEqualTo(10));
  });

  test('shield ignores spikes until immunity expires', () {
    final engine = BalloonAscentEngine(random: Random(6))..start();
    engine.barriers.add(SkyBarrier(
      y: BalloonAscentEngine.balloonY + 10,
      gapCenter: 650,
      gapWidth: 80,
    ));
    engine.shieldRemaining = 5;

    expect(engine.update(.01), isFalse);
    expect(engine.playing, isTrue);

    engine.shieldRemaining = 0;
    expect(engine.update(.01), isTrue);
    expect(engine.gameOver, isTrue);
  });

  testWidgets('Balloon Ascent starts with touch controls', (tester) async {
    SharedPreferences.setMockInitialValues({
      'game_high_score_balloon-ascent': 70,
    });

    await tester.pumpWidget(MaterialApp(
      home: BalloonAscentV2(
        api: ApiClient(baseUrl: 'http://127.0.0.1:1/api/v1'),
        game: const {
          'id': 13,
          'name': 'Balloon Ascent',
          'slug': 'balloon-ascent',
        },
      ),
    ));
    await tester.pump();

    expect(find.byKey(const Key('balloon-game-canvas')), findsOneWidget);
    expect(find.textContaining('Drag left or right'), findsOneWidget);
    expect(find.textContaining('BEST 00070'), findsOneWidget);

    await tester.tap(find.byKey(const Key('balloon-start')));
    await tester.pump(const Duration(milliseconds: 80));

    expect(find.byKey(const Key('balloon-start')), findsNothing);
    expect(find.textContaining('Every bubble adds 10 points'), findsOneWidget);
  });
}
