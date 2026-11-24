import 'package:flutter_test/flutter_test.dart';
import 'package:digiink_app/main.dart';

void main() {
  testWidgets('App loads smoke test', (WidgetTester tester) async {
    await tester.pumpWidget(const DigiInkApp());
    expect(find.byType(DigiInkApp), findsOneWidget);
  });
}
