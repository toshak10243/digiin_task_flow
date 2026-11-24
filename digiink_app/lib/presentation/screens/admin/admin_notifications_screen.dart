import 'package:flutter/material.dart';
import 'package:digiink_app/core/constants/app_colors.dart';

class AdminNotificationItem {
  final String id;
  final String title;
  final String message;
  final String time;
  final String type; // urgent, feedback, system, task
  final bool isRead;

  AdminNotificationItem({
    required this.id,
    required this.title,
    required this.message,
    required this.time,
    required this.type,
    this.isRead = false,
  });

  AdminNotificationItem copyWith({bool? isRead}) {
    return AdminNotificationItem(
      id: id,
      title: title,
      message: message,
      time: time,
      type: type,
      isRead: isRead ?? this.isRead,
    );
  }
}

class AdminNotificationsScreen extends StatefulWidget {
  const AdminNotificationsScreen({super.key});

  @override
  State<AdminNotificationsScreen> createState() => _AdminNotificationsScreenState();
}

class _AdminNotificationsScreenState extends State<AdminNotificationsScreen> {
  String _selectedFilter = 'all';

  final List<AdminNotificationItem> _notifications = [
    AdminNotificationItem(
      id: '1',
      title: 'Urgent Task Priority Escalation',
      message: 'Task DI/NOV26/PEARL/071 (Saputo Inc. Financial Case Study) marked as Urgent with 23:00 deadline.',
      time: '10 mins ago',
      type: 'urgent',
      isRead: false,
    ),
    AdminNotificationItem(
      id: '2',
      title: 'Client Feedback & Screenshot Uploaded',
      message: 'BDE Pearl added feedback & image proof for DI/NOV26/PEARL/071: "Include latest 2025 Q3 reports".',
      time: '35 mins ago',
      type: 'feedback',
      isRead: false,
    ),
    AdminNotificationItem(
      id: '3',
      title: 'Additional Materials Attached',
      message: 'Client provided supplemental CSV dataset on DI/NOV26/PEARL/068. Synchronized to Drive.',
      time: '2 hours ago',
      type: 'task',
      isRead: true,
    ),
    AdminNotificationItem(
      id: '4',
      title: 'Quality Check Approved',
      message: 'QC Specialist Karan marked task DI/NOV26/JSR/028 as Completed and verified plagiarism score.',
      time: '4 hours ago',
      type: 'task',
      isRead: true,
    ),
    AdminNotificationItem(
      id: '5',
      title: 'Firebase Cloud Messaging Ready',
      message: 'Desktop and Mobile push notifications service account is active and connected with token sync.',
      time: 'Yesterday',
      type: 'system',
      isRead: true,
    ),
    AdminNotificationItem(
      id: '6',
      title: 'Rework Request Submitted',
      message: 'Task DI/NOV26/NAR/060 requires minor rework on supply chain bottleneck chapter.',
      time: 'Yesterday',
      type: 'urgent',
      isRead: true,
    ),
  ];

  void _markAllAsRead() {
    setState(() {
      for (int i = 0; i < _notifications.length; i++) {
        _notifications[i] = _notifications[i].copyWith(isRead: true);
      }
    });
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('All notifications marked as read'),
        duration: Duration(seconds: 2),
      ),
    );
  }

  void _triggerTestPush() {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        backgroundColor: AppColors.secondary,
        behavior: SnackBarBehavior.floating,
        content: Row(
          children: const [
            Icon(Icons.notifications_active_rounded, color: AppColors.accent, size: 22),
            SizedBox(width: 10),
            Expanded(
              child: Text(
                'Push Notification dispatched to admin devices successfully!',
                style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
              ),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final filtered = _notifications.where((n) {
      if (_selectedFilter == 'all') return true;
      if (_selectedFilter == 'unread') return !n.isRead;
      return n.type == _selectedFilter;
    }).toList();

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: const Text('System Alerts & Feed'),
        actions: [
          IconButton(
            icon: const Icon(Icons.done_all_rounded, color: AppColors.primary),
            tooltip: 'Mark all as read',
            onPressed: _markAllAsRead,
          ),
        ],
      ),
      body: Column(
        children: [
          // Push Diagnostic Banner
          Container(
            margin: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(
              color: AppColors.primary.withOpacity(0.08),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.primary.withOpacity(0.2)),
            ),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: AppColors.primary,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(Icons.cloud_done_rounded, color: Colors.white, size: 18),
                ),
                const SizedBox(width: 12),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'FCM Push Engine Active',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppColors.textPrimary),
                      ),
                      Text(
                        'Direct synchronization with DigiInk backend',
                        style: TextStyle(fontSize: 11, color: AppColors.textMuted),
                      ),
                    ],
                  ),
                ),
                OutlinedButton(
                  style: OutlinedButton.styleFrom(
                    visualDensity: VisualDensity.compact,
                    side: const BorderSide(color: AppColors.primary),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                  onPressed: _triggerTestPush,
                  child: const Text('Send Test', style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                ),
              ],
            ),
          ),

          // Category Chips
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Row(
              children: [
                _buildFilterChip('all', 'All Alerts'),
                _buildFilterChip('unread', 'Unread'),
                _buildFilterChip('urgent', 'Urgent'),
                _buildFilterChip('feedback', 'Feedback'),
                _buildFilterChip('system', 'System'),
              ],
            ),
          ),

          // Notifications List
          Expanded(
            child: filtered.isEmpty
                ? const Center(
                    child: Text(
                      'No notifications found in this category.',
                      style: TextStyle(color: AppColors.textMuted, fontSize: 13),
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                    itemCount: filtered.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 10),
                    itemBuilder: (context, idx) {
                      final item = filtered[idx];
                      return _buildNotificationCard(item);
                    },
                  ),
          ),
        ],
      ),
    );
  }

  Widget _buildFilterChip(String key, String label) {
    final isSelected = _selectedFilter == key;
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: FilterChip(
        label: Text(label),
        selected: isSelected,
        selectedColor: AppColors.primary,
        backgroundColor: AppColors.surface,
        labelStyle: TextStyle(
          color: isSelected ? Colors.white : AppColors.textSecondary,
          fontWeight: FontWeight.w600,
          fontSize: 12,
        ),
        side: BorderSide(
          color: isSelected ? AppColors.primary : AppColors.border,
        ),
        onSelected: (_) => setState(() => _selectedFilter = key),
      ),
    );
  }

  Widget _buildNotificationCard(AdminNotificationItem item) {
    Color indicatorColor;
    IconData iconData;

    switch (item.type) {
      case 'urgent':
        indicatorColor = AppColors.error;
        iconData = Icons.warning_amber_rounded;
        break;
      case 'feedback':
        indicatorColor = AppColors.accent;
        iconData = Icons.chat_bubble_outline_rounded;
        break;
      case 'system':
        indicatorColor = AppColors.info;
        iconData = Icons.settings_suggest_rounded;
        break;
      default:
        indicatorColor = AppColors.primary;
        iconData = Icons.assignment_outlined;
    }

    return Container(
      decoration: BoxDecoration(
        color: item.isRead ? AppColors.surface : const Color(0xFFF0FDF4),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: item.isRead ? AppColors.border : indicatorColor.withOpacity(0.35),
          width: item.isRead ? 1 : 1.5,
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.02),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        leading: CircleAvatar(
          backgroundColor: indicatorColor.withOpacity(0.12),
          child: Icon(iconData, color: indicatorColor, size: 20),
        ),
        title: Row(
          children: [
            Expanded(
              child: Text(
                item.title,
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: item.isRead ? FontWeight.w600 : FontWeight.w800,
                  color: AppColors.textPrimary,
                ),
              ),
            ),
            if (!item.isRead)
              Container(
                width: 7,
                height: 7,
                margin: const EdgeInsets.only(left: 6),
                decoration: BoxDecoration(
                  color: indicatorColor,
                  shape: BoxShape.circle,
                ),
              ),
          ],
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const SizedBox(height: 4),
            Text(
              item.message,
              style: const TextStyle(fontSize: 12, color: AppColors.textSecondary, height: 1.3),
            ),
            const SizedBox(height: 6),
            Text(
              item.time,
              style: const TextStyle(fontSize: 10, color: AppColors.textMuted, fontWeight: FontWeight.w500),
            ),
          ],
        ),
        onTap: () {
          setState(() {
            final index = _notifications.indexWhere((n) => n.id == item.id);
            if (index != -1) {
              _notifications[index] = _notifications[index].copyWith(isRead: true);
            }
          });
        },
      ),
    );
  }
}
