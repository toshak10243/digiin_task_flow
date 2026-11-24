class AdminStatsModel {
  final int totalUsers;
  final int totalTasks;
  final int inProgress;
  final int completed;
  final int rework;
  final int urgent;
  final int totalWords;
  final int monthlyWords;
  final Map<String, int> roleCounts;

  AdminStatsModel({
    required this.totalUsers,
    required this.totalTasks,
    required this.inProgress,
    required this.completed,
    required this.rework,
    required this.urgent,
    required this.totalWords,
    required this.monthlyWords,
    required this.roleCounts,
  });

  factory AdminStatsModel.fromJson(Map<String, dynamic> json) {
    Map<String, int> roles = {};
    if (json['role_counts'] is Map) {
      (json['role_counts'] as Map).forEach((k, v) {
        roles[k.toString()] = int.tryParse(v.toString()) ?? 0;
      });
    }

    return AdminStatsModel(
      totalUsers: json['total_users'] ?? 0,
      totalTasks: json['total_tasks'] ?? 0,
      inProgress: json['in_progress'] ?? 0,
      completed: json['completed'] ?? 0,
      rework: json['rework'] ?? 0,
      urgent: json['urgent'] ?? 0,
      totalWords: json['total_words'] ?? 0,
      monthlyWords: json['monthly_words'] ?? 0,
      roleCounts: roles,
    );
  }
}

class DailyStatsModel {
  final int total;
  final int completed;
  final int working;
  final int rework;
  final int words;

  DailyStatsModel({
    required this.total,
    required this.completed,
    required this.working,
    required this.rework,
    required this.words,
  });

  factory DailyStatsModel.fromJson(Map<String, dynamic> json) {
    return DailyStatsModel(
      total: json['total'] ?? 0,
      completed: json['completed'] ?? 0,
      working: json['working'] ?? 0,
      rework: json['rework'] ?? 0,
      words: json['words'] ?? 0,
    );
  }
}

