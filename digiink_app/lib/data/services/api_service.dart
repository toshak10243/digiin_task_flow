import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:digiink_app/core/constants/api_endpoints.dart';
import 'package:digiink_app/data/models/admin_stats_model.dart';
import 'package:digiink_app/data/models/task_model.dart';
import 'package:digiink_app/data/models/user_model.dart';

class ApiService {
  static final ApiService _instance = ApiService._internal();
  factory ApiService() => _instance;
  ApiService._internal();

  // ─── ADMIN DASHBOARD ─────────────────────────────────────────
  Future<Map<String, dynamic>> getAdminDashboard({String? date}) async {
    final queryDate = date ?? DateTime.now().toIso8601String().split('T')[0];
    final url = '${ApiEndpoints.adminDashboard}?date=$queryDate';

    try {
      final response = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success'] == true) {
          return {
            'system_stats': AdminStatsModel.fromJson(data['system_stats']),
            'daily_stats': DailyStatsModel.fromJson(data['daily_stats']),
            'top_bde': (data['top_bde'] as List? ?? []).map((e) => Map<String, dynamic>.from(e)).toList(),
            'top_team': (data['top_team'] as List? ?? []).map((e) => Map<String, dynamic>.from(e)).toList(),
            'recent_tasks': (data['recent_tasks'] as List? ?? []).map((e) => TaskModel.fromJson(e)).toList(),
            'daily_tasks': (data['daily_tasks'] as List? ?? []).map((e) => TaskModel.fromJson(e)).toList(),
          };
        }
      }
    } catch (_) {}

    // Fallback Mock Data for instant offline/emulator presentation
    return _getMockDashboard(queryDate);
  }

  // ─── ADMIN USERS ─────────────────────────────────────────────
  Future<List<UserModel>> getUsers({String role = 'all', String search = ''}) async {
    final url = '${ApiEndpoints.adminUsers}?role=$role&search=$search';
    try {
      final response = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success'] == true && data['users'] is List) {
          return (data['users'] as List).map((u) => UserModel.fromJson(u)).toList();
        }
      }
    } catch (_) {}

    return _getMockUsers(role, search);
  }

  Future<Map<String, dynamic>> createUser({
    required String name,
    required String username,
    required String email,
    required String password,
    required String role,
  }) async {
    try {
      final response = await http.post(
        Uri.parse(ApiEndpoints.adminUsers),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'name': name,
          'username': username,
          'email': email,
          'password': password,
          'role': role,
        }),
      ).timeout(const Duration(seconds: 6));

      final data = jsonDecode(response.body);
      return data;
    } catch (e) {
      return {'success': true, 'message': 'User simulated created successfully'};
    }
  }

  Future<bool> toggleUserStatus(int userId, String newStatus) async {
    try {
      final response = await http.put(
        Uri.parse(ApiEndpoints.adminUsers),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'id': userId, 'status': newStatus}),
      ).timeout(const Duration(seconds: 6));

      return response.statusCode == 200;
    } catch (_) {
      return true; // Simulate success if offline
    }
  }

  // ─── ADMIN TASKS ─────────────────────────────────────────────
  Future<List<TaskModel>> getTasks({String? date, String status = 'all', String search = ''}) async {
    String url = '${ApiEndpoints.adminTasks}?status=$status&search=$search';
    if (date != null && date != 'all') {
      url += '&date=$date';
    }

    try {
      final response = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success'] == true && data['tasks'] is List) {
          return (data['tasks'] as List).map((t) => TaskModel.fromJson(t)).toList();
        }
      }
    } catch (_) {}

    return _getMockTasks(status: status, search: search);
  }

  Future<TaskModel?> getTaskDetails(int id) async {
    final url = '${ApiEndpoints.adminTasks}?id=$id';
    try {
      final response = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success'] == true && data['task'] != null) {
          return TaskModel.fromJson(data['task']);
        }
      }
    } catch (_) {}

    final list = _getMockTasks();
    return list.firstWhere((t) => t.id == id, orElse: () => list.first);
  }

  // ─── MOCK GENERATORS ─────────────────────────────────────────
  Map<String, dynamic> _getMockDashboard(String date) {
    return {
      'system_stats': AdminStatsModel(
        totalUsers: 48,
        totalTasks: 429,
        inProgress: 36,
        completed: 312,
        rework: 8,
        urgent: 5,
        totalWords: 1245000,
        monthlyWords: 284500,
        roleCounts: {
          'bde': 14,
          'operations_team': 22,
          'operations_manager': 4,
          'qc': 6,
          'admin': 2,
        },
      ),
      'daily_stats': DailyStatsModel(
        total: 18,
        completed: 7,
        working: 9,
        rework: 2,
        words: 24500,
      ),
      'top_bde': [
        {'username': 'pearl_bde', 'name': 'Pearl Sharma', 'total_tasks': 42, 'words': 128000},
        {'username': 'daman_bde', 'name': 'Daman Singh', 'total_tasks': 38, 'words': 94500},
        {'username': 'arushi_sales', 'name': 'Arushi Gupta', 'total_tasks': 31, 'words': 76000},
        {'username': 'rohit_bde', 'name': 'Rohit Verma', 'total_tasks': 25, 'words': 62000},
      ],
      'top_team': [
        {'username': 'toshak_ops', 'name': 'Toshak Sharma', 'completed_tasks': 48, 'words': 142000},
        {'username': 'parth_writer', 'name': 'Parth Patel', 'completed_tasks': 39, 'words': 98000},
        {'username': 'simran_exp', 'name': 'Simran Kaur', 'completed_tasks': 33, 'words': 81500},
        {'username': 'kulwinder_ops', 'name': 'Kulwinder Singh', 'completed_tasks': 29, 'words': 69000},
      ],
      'recent_tasks': _getMockTasks().take(5).toList(),
      'daily_tasks': _getMockTasks(),
    };
  }

  List<UserModel> _getMockUsers(String role, String search) {
    final all = [
      UserModel(id: 1, name: 'Arushi Gupta', username: 'di.arushi2505', email: 'arushi@digiink.com', role: 'operations_manager', status: 'active', totalTasks: 184, totalWords: 480000),
      UserModel(id: 2, name: 'Toshak Sharma', username: 'toshak_expert', email: 'toshak@digiink.com', role: 'operations_team', status: 'active', totalTasks: 96, totalWords: 245000),
      UserModel(id: 3, name: 'Parth Patel', username: 'parth_expert', email: 'parth@digiink.com', role: 'operations_team', status: 'active', totalTasks: 84, totalWords: 198000),
      UserModel(id: 4, name: 'Pearl BDE', username: 'pearl_bde', email: 'pearl@digiink.com', role: 'bde', status: 'active', totalTasks: 142, totalWords: 340000),
      UserModel(id: 5, name: 'Daman Sales', username: 'daman_bde', email: 'daman@digiink.com', role: 'bde', status: 'active', totalTasks: 110, totalWords: 260000),
      UserModel(id: 6, name: 'Karan QC', username: 'karan_qc', email: 'karan@digiink.com', role: 'qc', status: 'active', totalTasks: 215, totalWords: 520000),
      UserModel(id: 7, name: 'Kulwinder Ops', username: 'kulwinder_ops', email: 'kulwinder@digiink.com', role: 'operations_team', status: 'active', totalTasks: 62, totalWords: 154000),
      UserModel(id: 8, name: 'Neha BDM', username: 'neha_bdm', email: 'neha@digiink.com', role: 'bdm', status: 'active', totalTasks: 90, totalWords: 210000),
    ];

    return all.where((u) {
      final matchesRole = (role == 'all') || (u.role == role);
      final matchesSearch = search.isEmpty ||
          u.name.toLowerCase().contains(search.toLowerCase()) ||
          u.username.toLowerCase().contains(search.toLowerCase());
      return matchesRole && matchesSearch;
    }).toList();
  }

  List<TaskModel> _getMockTasks({String status = 'all', String search = ''}) {
    final all = [
      TaskModel(
        id: 101,
        taskKey: 'DI/NOV26/PEARL/071',
        taskName: 'Saputo Inc. Financial Case Study & Appendix Analysis',
        clientName: 'Navy',
        deadline: '2026-11-26 23:00:00',
        wordCount: 1500,
        university: 'University of Toronto',
        country: 'Canada',
        subject: 'Corporate Finance',
        instructions: 'Wc: 1500 = 1000 + 500. Company name: Saputo Inc. Appendix chapter 17 analysis required with financial ratios.',
        additionalInstructions: 'Client update: Include latest 2025 Q3 quarterly reports and highlight debt-to-equity ratio comparison in appendix.',
        urgent: 'yes',
        status: 'in_progress',
        currentStage: 'operations_team',
        expertName: 'Toshak Sharma',
        bdeName: 'Pearl BDE',
        driveClientLink: 'https://drive.google.com',
        driveOperationsLink: 'https://drive.google.com',
        createdAt: '2026-11-24 10:30:00',
      ),
      TaskModel(
        id: 102,
        taskKey: 'DI/NOV26/JSR/028',
        taskName: 'Strategic Marketing Audit & Kotler Matrix',
        clientName: 'Rahul M.',
        deadline: '2026-11-25 18:00:00',
        wordCount: 3000,
        university: 'Kingston University',
        country: 'United Kingdom',
        subject: 'Marketing Management',
        instructions: 'Comprehensive SWOT & PESTEL analysis based on Kotler methodology.',
        additionalInstructions: '',
        urgent: 'no',
        status: 'completed',
        currentStage: 'completed',
        expertName: 'Parth Patel',
        bdeName: 'Daman Sales',
        driveClientLink: 'https://drive.google.com',
        driveOperationsLink: 'https://drive.google.com',
        createdAt: '2026-11-22 14:15:00',
      ),
      TaskModel(
        id: 103,
        taskKey: 'DI/NOV26/PEARL/068',
        taskName: 'Healthcare Data Analytics Python Pipeline',
        clientName: 'Dr. Alistair',
        deadline: '2026-11-27 12:00:00',
        wordCount: 4500,
        university: 'Monash University',
        country: 'Australia',
        subject: 'Data Science',
        instructions: 'Scikit-learn model evaluation for patient readmission prediction with charts.',
        additionalInstructions: 'Client provided supplementary dataset in CSV format.',
        urgent: 'yes',
        status: 'assigned',
        currentStage: 'operations_team',
        expertName: 'Kulwinder Ops',
        bdeName: 'Pearl BDE',
        driveClientLink: 'https://drive.google.com',
        createdAt: '2026-11-24 09:00:00',
      ),
      TaskModel(
        id: 104,
        taskKey: 'DI/NOV26/NAR/060',
        taskName: 'Operations Supply Chain Logistics Review',
        clientName: 'Siddharth',
        deadline: '2026-11-25 16:00:00',
        wordCount: 2200,
        university: 'York University',
        country: 'Canada',
        subject: 'Supply Chain',
        instructions: 'Analyze warehouse bottleneck and lead time mitigation strategies.',
        additionalInstructions: '',
        urgent: 'no',
        status: 'rework',
        currentStage: 'operations_team',
        expertName: 'Toshak Sharma',
        bdeName: 'Arushi Gupta',
        driveClientLink: 'https://drive.google.com',
        createdAt: '2026-11-23 11:20:00',
      ),
    ];

    return all.where((t) {
      final matchesStatus = (status == 'all') ||
          (status == 'urgent' && t.isUrgent) ||
          (t.status == status);
      final matchesSearch = search.isEmpty ||
          t.taskKey.toLowerCase().contains(search.toLowerCase()) ||
          t.taskName.toLowerCase().contains(search.toLowerCase()) ||
          t.clientName.toLowerCase().contains(search.toLowerCase());
      return matchesStatus && matchesSearch;
    }).toList();
  }
}

