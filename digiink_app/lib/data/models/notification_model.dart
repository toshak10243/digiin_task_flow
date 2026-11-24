class NotificationModel {
  final int id;
  final int userId;
  final int? taskId;
  final String? taskKey;
  final String? taskName;
  final String? taskStatus;
  final String title;
  final String type;
  final bool isRead;
  final String createdAt;
  final String? recipientName;

  NotificationModel({
    required this.id,
    required this.userId,
    this.taskId,
    this.taskKey,
    this.taskName,
    this.taskStatus,
    required this.title,
    required this.type,
    required this.isRead,
    required this.createdAt,
    this.recipientName,
  });

  factory NotificationModel.fromJson(Map<String, dynamic> json) {
    return NotificationModel(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id']?.toString() ?? '0') ?? 0,
      userId: json['user_id'] is int ? json['user_id'] : int.tryParse(json['user_id']?.toString() ?? '0') ?? 0,
      taskId: json['task_id'] != null ? (json['task_id'] is int ? json['task_id'] : int.tryParse(json['task_id'].toString())) : null,
      taskKey: json['task_key']?.toString(),
      taskName: json['task_name']?.toString(),
      taskStatus: json['task_status']?.toString(),
      title: json['title']?.toString() ?? '',
      type: json['type']?.toString() ?? 'system',
      isRead: json['is_read'] == true || json['is_read'] == 1 || json['is_read'] == '1',
      createdAt: json['created_at']?.toString() ?? '',
      recipientName: json['recipient_name']?.toString(),
    );
  }

  NotificationModel copyWith({bool? isRead}) {
    return NotificationModel(
      id: id,
      userId: userId,
      taskId: taskId,
      taskKey: taskKey,
      taskName: taskName,
      taskStatus: taskStatus,
      title: title,
      type: type,
      isRead: isRead ?? this.isRead,
      createdAt: createdAt,
      recipientName: recipientName,
    );
  }
}
