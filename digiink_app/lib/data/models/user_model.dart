class UserModel {
  final int id;
  final String name;
  final String username;
  final String email;
  final String role;
  final String status;
  final int totalTasks;
  final int totalWords;
  final String? createdAt;

  UserModel({
    required this.id,
    required this.name,
    required this.username,
    required this.email,
    required this.role,
    required this.status,
    this.totalTasks = 0,
    this.totalWords = 0,
    this.createdAt,
  });

  bool get isActive => status.toLowerCase() == 'active';

  factory UserModel.fromJson(Map<String, dynamic> json) {
    return UserModel(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      name: json['name'] ?? json['username'] ?? '',
      username: json['username'] ?? '',
      email: json['email'] ?? '',
      role: json['role'] ?? 'operations_team',
      status: json['status'] ?? 'active',
      totalTasks: json['total_tasks'] is int ? json['total_tasks'] : int.tryParse(json['total_tasks']?.toString() ?? '0') ?? 0,
      totalWords: json['total_words'] is int ? json['total_words'] : int.tryParse(json['total_words']?.toString() ?? '0') ?? 0,
      createdAt: json['created_at'],
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'username': username,
    'email': email,
    'role': role,
    'status': status,
  };

  String get roleDisplay {
    switch (role.toLowerCase()) {
      case 'admin':
        return 'Super Admin';
      case 'operations_manager':
      case 'om':
        return 'Ops Manager';
      case 'operations_team':
        return 'Ops Expert';
      case 'bde':
        return 'BDE';
      case 'bdm':
      case 'bdmanager':
        return 'BDM';
      case 'qc':
        return 'Quality Control';
      default:
        return role.toUpperCase();
    }
  }
}

