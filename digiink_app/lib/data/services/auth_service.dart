import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:http/http.dart' as http;
import 'package:digiink_app/data/models/user_model.dart';
import 'package:digiink_app/core/constants/api_endpoints.dart';

class AuthService {
  static final AuthService _instance = AuthService._internal();
  factory AuthService() => _instance;
  AuthService._internal();

  UserModel? _currentUser;
  String? _token;

  UserModel? get currentUser => _currentUser;
  String? get token => _token;
  bool get isLoggedIn => _currentUser != null;

  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    final userJson = prefs.getString('user_data');
    final savedToken = prefs.getString('auth_token');

    if (userJson != null && savedToken != null) {
      try {
        _currentUser = UserModel.fromJson(jsonDecode(userJson));
        _token = savedToken;
      } catch (_) {}
    }
  }

  Future<Map<String, dynamic>> login(String username, String password) async {
    try {
      final response = await http.post(
        Uri.parse(ApiEndpoints.login),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'username': username.trim(),
          'password': password.trim(),
        }),
      ).timeout(const Duration(seconds: 8));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success'] == true) {
          _currentUser = UserModel.fromJson(data['user']);
          _token = data['token'];

          final prefs = await SharedPreferences.getInstance();
          await prefs.setString('user_data', jsonEncode(data['user']));
          await prefs.setString('auth_token', _token!);

          return {'success': true, 'user': _currentUser};
        } else {
          return {'success': false, 'message': data['message'] ?? 'Login failed'};
        }
      } else {
        final data = jsonDecode(response.body);
        return {'success': false, 'message': data['message'] ?? 'Invalid credentials'};
      }
    } catch (e) {
      // Local fallback for offline / mock testing:
      if (username.toLowerCase() == 'admin') {
        _currentUser = UserModel(
          id: 1,
          name: 'Super Administrator',
          username: 'admin',
          email: 'admin@digiink.com',
          role: 'admin',
          status: 'active',
        );
        _token = 'mock_admin_token_12345';
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('user_data', jsonEncode(_currentUser!.toJson()));
        await prefs.setString('auth_token', _token!);
        return {'success': true, 'user': _currentUser, 'is_mock': true};
      } else if (username.toLowerCase() == 'bde') {
        _currentUser = UserModel(
          id: 2,
          name: 'Toshak Sharma',
          username: 'bde',
          email: 'bde@digiink.com',
          role: 'bde',
          status: 'active',
        );
        _token = 'mock_bde_token_12345';
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('user_data', jsonEncode(_currentUser!.toJson()));
        await prefs.setString('auth_token', _token!);
        return {'success': true, 'user': _currentUser, 'is_mock': true};
      }
      return {'success': false, 'message': 'Network error. Please check server connection.'};
    }
  }

  Future<void> logout() async {
    _currentUser = null;
    _token = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('user_data');
    await prefs.remove('auth_token');
  }
}

