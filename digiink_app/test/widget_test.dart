import 'package:flutter_test/flutter_test.dart';
import 'package:digiink_app/main.dart';

void main() {
  testWidgets('App loads smoke test', (WidgetTester tester) async {
    await tester.pumpWidget(const DigiInkApp());
    await tester.pump(const Duration(milliseconds: 1500));
    expect(find.byType(DigiInkApp), findsOneWidget);
  });
}
