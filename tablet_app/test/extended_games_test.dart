import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tableplay_tablet/core/api_client.dart';
import 'package:tableplay_tablet/features/extended_games.dart';
import 'package:tableplay_tablet/theme/tableplay_theme.dart';

Widget host(Widget game) => MaterialApp(theme: tablePlayTheme(), home: game);

void main() {
  late ApiClient api;
  setUp(() => api = ApiClient(baseUrl: 'http://127.0.0.1:1/api/v1'));
  tearDown(() => api.close());

  testWidgets('Word Scramble presents an answer round', (tester) async {
    await tester.pumpWidget(host(WordScrambleV2(
        api: api, game: const {'id': 8, 'name': 'Word Scramble'})));
    expect(find.byKey(const Key('scrambled-word')), findsOneWidget);
    expect(find.byKey(const Key('word-submit')), findsOneWidget);
  });

  testWidgets('Reaction Duel provides controls for both players',
      (tester) async {
    await tester.pumpWidget(host(ReactionDuelV2(
        api: api, game: const {'id': 9, 'name': 'Reaction Duel'})));
    expect(find.byKey(const Key('reaction-player-1')), findsOneWidget);
    expect(find.byKey(const Key('reaction-player-2')), findsOneWidget);
  });

  testWidgets('Air Hockey starts with player one attacking', (tester) async {
    await tester.pumpWidget(host(
        AirHockeyV2(api: api, game: const {'id': 10, 'name': 'Air Hockey'})));
    expect(find.text('Player 1 attacks'), findsOneWidget);
    expect(find.byKey(const Key('hockey-player-1')), findsOneWidget);
  });

  testWidgets('Quiz Battle renders four-player scoring and answers',
      (tester) async {
    await tester.pumpWidget(host(
        QuizBattleV2(api: api, game: const {'id': 11, 'name': 'Quiz Battle'})));
    expect(find.text('Player 1 · Question 1/8'), findsOneWidget);
    expect(find.byKey(const Key('quiz-answer-0')), findsOneWidget);
  });

  testWidgets('Tap Elimination renders four player zones', (tester) async {
    await tester.pumpWidget(host(TapEliminationV2(
        api: api, game: const {'id': 12, 'name': 'Tap Elimination'})));
    expect(find.byKey(const Key('elimination-player-0')), findsOneWidget);
    expect(find.byKey(const Key('elimination-player-3')), findsOneWidget);
  });

  testWidgets('Ludo Mini renders four racers and dice', (tester) async {
    await tester.pumpWidget(host(
        LudoMiniV2(api: api, game: const {'id': 13, 'name': 'Ludo Mini'})));
    expect(find.byKey(const Key('ludo-score-0')), findsOneWidget);
    expect(find.byKey(const Key('ludo-score-3')), findsOneWidget);
    expect(find.byKey(const Key('ludo-roll')), findsOneWidget);
  });
}
