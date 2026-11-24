import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:digiink_app/core/constants/api_endpoints.dart';
import 'package:digiink_app/data/models/admin_stats_model.dart';
import 'package:digiink_app/data/models/notification_model.dart';
import 'package:digiink_app/data/models/task_model.dart';
import 'package:digiink_app/data/models/user_model.dart';
import 'package:digiink_app/data/services/auth_service.dart';

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
  Future<Map<String, dynamic>> getTasksPaginated({
    String? date,
    String status = 'all',
    String search = '',
    int page = 1,
    int limit = 20,
  }) async {
    String url = '${ApiEndpoints.adminTasks}?status=$status&search=$search&page=$page&limit=$limit';
    if (date != null && date != 'all') {
      url += '&date=$date';
    }

    try {
      final response = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success'] == true && data['tasks'] is List) {
          final tasks = (data['tasks'] as List).map((t) => TaskModel.fromJson(t)).toList();
          final total = data['total'] is int ? data['total'] : int.tryParse(data['total']?.toString() ?? '0') ?? tasks.length;
          final totalPages = data['total_pages'] is int ? data['total_pages'] : int.tryParse(data['total_pages']?.toString() ?? '1') ?? 1;
          return {
            'tasks': tasks,
            'total': total,
            'page': page,
            'limit': limit,
            'totalPages': totalPages,
          };
        }
      }
    } catch (_) {}

    final all = _getMockTasks(status: status, search: search);
    final total = all.length;
    final totalPages = (total / limit).ceil() > 0 ? (total / limit).ceil() : 1;
    final startIndex = (page - 1) * limit;
    final paged = startIndex < total ? all.skip(startIndex).take(limit).toList() : <TaskModel>[];

    return {
      'tasks': paged,
      'total': total,
      'page': page,
      'limit': limit,
      'totalPages': totalPages,
    };
  }

  Future<List<TaskModel>> getTasks({String? date, String status = 'all', String search = ''}) async {
    final res = await getTasksPaginated(date: date, status: status, search: search, page: 1, limit: 100);
    return res['tasks'] as List<TaskModel>;
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

  // ─── ADMIN NOTIFICATIONS ──────────────────────────────────────
  Future<Map<String, dynamic>> getNotifications({
    String type = 'all',
    String search = '',
    int limit = 100,
    int offset = 0,
  }) async {
    final url = '${ApiEndpoints.adminNotifications}?type=$type&search=$search&limit=$limit&offset=$offset';
    try {
      final response = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success'] == true && data['notifications'] is List) {
          final items = (data['notifications'] as List)
              .map((n) => NotificationModel.fromJson(n))
              .toList();
          final stats = data['stats'] is Map ? data['stats'] : {};
          return {
            'notifications': items,
            'total': data['total'] ?? items.length,
            'unread': stats['unread'] ?? 0,
            'today': stats['today'] ?? 0,
          };
        }
      }
    } catch (_) {}

    return _getMockNotifications(type: type, search: search);
  }

  Future<bool> markNotificationRead(int id) async {
    try {
      final response = await http.post(
        Uri.parse(ApiEndpoints.adminNotifications),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'action': 'mark_read', 'id': id}),
      ).timeout(const Duration(seconds: 5));
      return response.statusCode == 200;
    } catch (_) {
      return true;
    }
  }

  Future<bool> markAllNotificationsRead() async {
    try {
      final response = await http.post(
        Uri.parse(ApiEndpoints.adminNotifications),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'action': 'mark_all_read'}),
      ).timeout(const Duration(seconds: 5));
      return response.statusCode == 200;
    } catch (_) {
      return true;
    }
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
      UserModel(id: 14, name: 'Arushi Gupta', username: 'di.arushi2505', email: 'arushi@digiink.com', role: 'operations_manager', status: 'active', totalTasks: 184, totalWords: 480000),
      UserModel(id: 33, name: 'Suvjeet Nayak', username: 'suv.bdm', email: 'suvjeet@digiink.com', role: 'bdmanager', status: 'active', totalTasks: 120, totalWords: 310000),
      UserModel(id: 28, name: 'Isha Khandelwal', username: 'di.bdm', email: 'isha@digiink.com', role: 'bdmanager', status: 'active', totalTasks: 95, totalWords: 240000),
      UserModel(id: 27, name: 'Priya BDM', username: 'priya123', email: 'priya@digiink.com', role: 'bdmanager', status: 'inactive', totalTasks: 45, totalWords: 110000),
      UserModel(id: 26, name: 'Toshak Sharma', username: 'toshak123', email: 'toshak@digiink.com', role: 'operations_team', status: 'active', totalTasks: 96, totalWords: 245000),
      UserModel(id: 19, name: 'Parth Patel', username: 'di.parth2505', email: 'parth@digiink.com', role: 'operations_team', status: 'active', totalTasks: 84, totalWords: 198000),
      UserModel(id: 36, name: 'Piyush Ops', username: 'di.piyush2505', email: 'piyush@digiink.com', role: 'operations_team', status: 'active', totalTasks: 62, totalWords: 154000),
      UserModel(id: 38, name: 'Toshak BDE', username: 'toshak', email: 'toshak.sales@digiink.com', role: 'bde', status: 'active', totalTasks: 142, totalWords: 340000),
      UserModel(id: 37, name: 'Tisha BDE', username: 'di.tisha2505', email: 'tisha@digiink.com', role: 'bde', status: 'active', totalTasks: 110, totalWords: 260000),
      UserModel(id: 6, name: 'Karan QC', username: 'karan_qc', email: 'karan@digiink.com', role: 'qc', status: 'active', totalTasks: 215, totalWords: 520000),
    ];

    return all.where((u) {
      bool matchesRole;
      if (role == 'all') {
        matchesRole = true;
      } else if (role == 'managers' || role == 'manager') {
        matchesRole = u.role == 'operations_manager' || u.role == 'bdmanager' || u.role == 'bdm';
      } else if (role == 'bdm' || role == 'bdmanager') {
        matchesRole = u.role == 'bdmanager' || u.role == 'bdm';
      } else if (role == 'operations_manager' || role == 'om') {
        matchesRole = u.role == 'operations_manager';
      } else {
        matchesRole = u.role == role;
      }

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

    // Generate additional realistic mock tasks so pagination (20 per page) can be previewed seamlessly
    final List<TaskModel> extended = List.from(all);
    for (int i = 5; i <= 45; i++) {
      final base = all[i % all.length];
      extended.add(TaskModel(
        id: 100 + i,
        taskKey: 'DI/NOV26/BATCH/${i.toString().padLeft(3, '0')}',
        taskName: '${base.taskName} (Part $i)',
        clientName: base.clientName,
        deadline: base.deadline,
        wordCount: base.wordCount,
        university: base.university,
        country: base.country,
        subject: base.subject,
        instructions: base.instructions,
        additionalInstructions: base.additionalInstructions,
        urgent: i % 4 == 0 ? 'yes' : 'no',
        status: ['assigned', 'in_progress', 'completed', 'rework'][i % 4],
        currentStage: base.currentStage,
        expertName: base.expertName,
        bdeName: base.bdeName,
        driveClientLink: base.driveClientLink,
        driveOperationsLink: base.driveOperationsLink,
        createdAt: '2026-11-24 10:00:00',
      ));
    }

    return extended.where((t) {
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

  Map<String, dynamic> _getMockNotifications({String type = 'all', String search = ''}) {
    final list = [
      NotificationModel(
        id: 1,
        userId: 1,
        taskId: 101,
        taskKey: 'DI/NOV26/PEARL/071',
        taskName: 'Saputo Inc. Financial Case Study',
        taskStatus: 'in_progress',
        title: 'New Task Created: DI/NOV26/PEARL/071 by Pearl BDE',
        type: 'task_created',
        isRead: false,
        createdAt: '10 mins ago',
      ),
      NotificationModel(
        id: 2,
        userId: 1,
        taskId: 101,
        taskKey: 'DI/NOV26/PEARL/071',
        taskName: 'Saputo Inc. Financial Case Study',
        taskStatus: 'in_progress',
        title: 'Feedback Added: "Include latest 2025 Q3 quarterly reports in appendix"',
        type: 'feedback',
        isRead: false,
        createdAt: '25 mins ago',
      ),
      NotificationModel(
        id: 3,
        userId: 1,
        taskId: 103,
        taskKey: 'DI/NOV26/JSR/028',
        taskName: 'Machine Learning Healthcare Predictive Model',
        taskStatus: 'assigned',
        title: 'Additional Files Attached: Supplemental CSV dataset synchronized to Drive',
        type: 'additional_files',
        isRead: true,
        createdAt: '1 hour ago',
      ),
      NotificationModel(
        id: 4,
        userId: 1,
        taskId: 102,
        taskKey: 'DI/NOV26/DAMAN/065',
        taskName: 'International Business Strategy Evaluation',
        taskStatus: 'completed',
        title: 'Task Completed: DI/NOV26/DAMAN/065 approved by QC Specialist',
        type: 'task_completed',
        isRead: true,
        createdAt: '3 hours ago',
      ),
      NotificationModel(
        id: 5,
        userId: 1,
        taskId: 104,
        taskKey: 'DI/NOV26/NAR/060',
        taskName: 'Operations Supply Chain Logistics Review',
        taskStatus: 'rework',
        title: 'Rework Required: Supply chain bottleneck chapter requires deeper analysis',
        type: 'task_rework',
        isRead: true,
        createdAt: 'Yesterday',
      ),
      NotificationModel(
        id: 6,
        userId: 1,
        taskId: 101,
        taskKey: 'DI/NOV26/PEARL/071',
        taskName: 'Saputo Inc. Financial Case Study',
        taskStatus: 'in_progress',
        title: 'Priority Escalation: Task marked as Urgent with 23:00 deadline',
        type: 'urgent_task',
        isRead: false,
        createdAt: 'Yesterday',
      ),
    ];

    final filtered = list.where((n) {
      bool matchesType;
      if (type == 'all') {
        matchesType = true;
      } else if (type == 'urgent') {
        matchesType = n.type == 'urgent_task' || n.title.toLowerCase().contains('urgent');
      } else if (type == 'feedback') {
        matchesType = n.type == 'feedback' || n.title.toLowerCase().contains('feedback');
      } else if (type == 'task') {
        matchesType = n.type.startsWith('task') || n.type == 'additional_files';
      } else {
        matchesType = n.type == type;
      }

      final matchesSearch = search.isEmpty ||
          n.title.toLowerCase().contains(search.toLowerCase()) ||
          (n.taskKey?.toLowerCase().contains(search.toLowerCase()) ?? false);
      return matchesType && matchesSearch;
    }).toList();

    return {
      'notifications': filtered,
      'total': filtered.length,
      'unread': filtered.where((n) => !n.isRead).length,
      'today': filtered.take(3).length,
    };
  }

  // ══════════════════════════════════════════════════════════
  // ─── BDE METHODS ──────────────────────────────────────────
  // ══════════════════════════════════════════════════════════

  Future<Map<String, dynamic>> getBdeDashboard({String? date}) async {
    final uid = AuthService().currentUser?.id ?? 0;
    final dateParam = date != null ? '&date=$date' : '';
    final url = '${ApiEndpoints.bdeDashboard}?user_id=$uid$dateParam';

    try {
      final res = await http.get(
        Uri.parse(url),
        headers: {
          'Content-Type': 'application/json',
          'X-User-Id': uid.toString(),
        },
      ).timeout(const Duration(seconds: 8));

      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['success'] == true) {
          final recentTasks = ((data['recent_tasks'] ?? []) as List)
              .map((json) => TaskModel.fromJson(json))
              .toList();
          final attentionTasks = ((data['attention_tasks'] ?? []) as List)
              .map((json) => TaskModel.fromJson(json))
              .toList();
          final dailyTasks = ((data['daily_tasks'] ?? []) as List)
              .map((json) => TaskModel.fromJson(json))
              .toList();

          return {
            'success': true,
            'greeting': data['greeting'] ?? 'Good day',
            'stats': data['stats'] ?? {},
            'pipeline': data['pipeline'] ?? [],
            'daily_stats': data['daily_stats'] ?? {},
            'daily_tasks': dailyTasks,
            'attention_tasks': attentionTasks,
            'recent_tasks': recentTasks,
          };
        }
      }
    } catch (_) {}

    // Mock fallback for BDE Dashboard
    return _generateMockBdeDashboard(date);
  }

  Future<Map<String, dynamic>> getBdeTasksPaginated({
    String? date,
    String status = 'all',
    String search = '',
    int page = 1,
    int limit = 20,
  }) async {
    final uid = AuthService().currentUser?.id ?? 0;
    final qParams = <String, String>{
      'user_id': uid.toString(),
      'status': status,
      'page': page.toString(),
      'limit': limit.toString(),
    };
    if (date != null && date != 'all') qParams['date'] = date;
    if (search.isNotEmpty) qParams['search'] = search;

    final uri = Uri.parse(ApiEndpoints.bdeTasks).replace(queryParameters: qParams);

    try {
      final res = await http.get(
        uri,
        headers: {
          'Content-Type': 'application/json',
          'X-User-Id': uid.toString(),
        },
      ).timeout(const Duration(seconds: 8));

      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        if (data['success'] == true) {
          final list = (data['tasks'] as List)
              .map((json) => TaskModel.fromJson(json))
              .toList();
          return {
            'success': true,
            'total': data['total'] ?? list.length,
            'page': data['page'] ?? page,
            'limit': data['limit'] ?? limit,
            'total_pages': data['total_pages'] ?? 1,
            'tasks': list,
          };
        }
      }
    } catch (_) {}

    // Mock fallback
    return getTasksPaginated(date: date, status: status, search: search, page: page, limit: limit);
  }

  Future<Map<String, dynamic>> checkTaskKey(String taskKey) async {
    final url = '${ApiEndpoints.bdeCreateTask}?action=check_task_key&task_key=${Uri.encodeComponent(taskKey)}';
    try {
      final res = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 5));
      if (res.statusCode == 200) {
        return jsonDecode(res.body);
      }
    } catch (_) {}
    return {'exists': false};
  }

  Future<Map<String, dynamic>> createBdeTask(Map<String, dynamic> taskData) async {
    final uid = AuthService().currentUser?.id ?? 0;
    final payload = {
      ...taskData,
      'user_id': uid,
    };

    try {
      final res = await http.post(
        Uri.parse(ApiEndpoints.bdeCreateTask),
        headers: {
          'Content-Type': 'application/json',
          'X-User-Id': uid.toString(),
        },
        body: jsonEncode(payload),
      ).timeout(const Duration(seconds: 10));

      final data = jsonDecode(res.body);
      return data;
    } catch (e) {
      // Local fallback mock creation
      return {
        'success': true,
        'message': 'Task created successfully (offline mode)',
        'task_id': DateTime.now().millisecondsSinceEpoch,
        'task_key': taskData['task_key'],
      };
    }
  }

  Future<Map<String, dynamic>> saveAdditionalFiles({
    required int taskId,
    String? additionalInstructions,
    String? additionalLink,
  }) async {
    try {
      final res = await http.post(
        Uri.parse(ApiEndpoints.bdeAdditionalFiles),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'task_id': taskId,
          'additional_instructions': additionalInstructions ?? '',
          'additional_link': additionalLink ?? '',
        }),
      ).timeout(const Duration(seconds: 8));

      return jsonDecode(res.body);
    } catch (e) {
      return {
        'success': true,
        'message': 'Additional instructions updated successfully (offline mode)',
      };
    }
  }

  Future<Map<String, dynamic>> toggleDelivered(int taskId) async {
    final uid = AuthService().currentUser?.id ?? 0;
    try {
      final res = await http.post(
        Uri.parse(ApiEndpoints.bdeTasks),
        headers: {
          'Content-Type': 'application/json',
          'X-User-Id': uid.toString(),
        },
        body: jsonEncode({
          'action': 'toggle_delivered',
          'task_id': taskId,
        }),
      ).timeout(const Duration(seconds: 6));

      return jsonDecode(res.body);
    } catch (e) {
      return {
        'success': true,
        'delivered_to_client': 1,
        'message': 'Toggled delivered status',
      };
    }
  }

  Future<Map<String, dynamic>> submitFeedback({
    required int taskId,
    required String feedback,
    bool isRework = false,
  }) async {
    try {
      final res = await http.post(
        Uri.parse(ApiEndpoints.bdeFeedback),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'task_id': taskId,
          'feedback': feedback,
          'is_rework': isRework,
        }),
      ).timeout(const Duration(seconds: 8));

      return jsonDecode(res.body);
    } catch (e) {
      return {
        'success': true,
        'message': isRework ? 'Rework requested successfully' : 'Feedback submitted',
      };
    }
  }

  Map<String, dynamic> _generateMockBdeDashboard(String? date) {
    final mockTasks = _getMockTasks();
    final recent = mockTasks.take(6).toList();
    final attention = mockTasks.where((t) => t.isUrgent || t.isRework).take(4).toList();

    return {
      'success': true,
      'greeting': 'Good day, Toshak',
      'stats': {
        'total': mockTasks.length,
        'new_count': mockTasks.where((t) => t.status == 'new').length,
        'in_progress': mockTasks.where((t) => t.status == 'in_progress').length,
        'rework': mockTasks.where((t) => t.status == 'rework').length,
        'completed': mockTasks.where((t) => t.status == 'completed').length,
        'urgent': mockTasks.where((t) => t.isUrgent).length,
        'overdue': 2,
        'total_words': 68500,
        'monthly_words': 24000,
        'unread_notifs': 3,
      },
      'pipeline': [
        {'stage': 'new', 'label': 'New', 'color': '#a1a1aa', 'count': 4, 'percentage': 12.5},
        {'stage': 'assigned', 'label': 'Assigned', 'color': '#f2a93b', 'count': 6, 'percentage': 18.8},
        {'stage': 'in_progress', 'label': 'Working', 'color': '#3b6cf6', 'count': 10, 'percentage': 31.2},
        {'stage': 'rework', 'label': 'Rework', 'color': '#e11d48', 'count': 3, 'percentage': 9.4},
        {'stage': 'approved', 'label': 'Approved', 'color': '#7c5cf0', 'count': 4, 'percentage': 12.5},
        {'stage': 'completed', 'label': 'Completed', 'color': '#0f9d68', 'count': 5, 'percentage': 15.6},
      ],
      'daily_stats': {
        'total': 5,
        'completed': 2,
        'working': 2,
        'new': 1,
        'words': 6500,
        'urgent': 1,
      },
      'daily_tasks': recent.take(4).toList(),
      'attention_tasks': attention,
      'recent_tasks': recent,
    };
  }
}

