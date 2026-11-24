int _parseInt(dynamic v) {
  if (v == null) return 0;
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v.toString()) ?? 0;
}

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
        roles[k.toString()] = _parseInt(v);
      });
    }

    return AdminStatsModel(
      totalUsers: _parseInt(json['total_users']),
      totalTasks: _parseInt(json['total_tasks']),
      inProgress: _parseInt(json['in_progress']),
      completed: _parseInt(json['completed']),
      rework: _parseInt(json['rework']),
      urgent: _parseInt(json['urgent']),
      totalWords: _parseInt(json['total_words']),
      monthlyWords: _parseInt(json['monthly_words']),
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
      total: _parseInt(json['total']),
      completed: _parseInt(json['completed']),
      working: _parseInt(json['working']),
      rework: _parseInt(json['rework']),
      words: _parseInt(json['words']),
    );
  }
}
