import 'package:flutter/material.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/models/task_model.dart';
import 'package:digiink_app/data/services/api_service.dart';
import 'package:digiink_app/data/services/auth_service.dart';
import 'package:digiink_app/presentation/screens/admin/admin_task_detail_screen.dart';
import 'package:digiink_app/presentation/screens/bde/bde_create_task_screen.dart';
import 'package:digiink_app/presentation/widgets/task_card.dart';
import 'package:intl/intl.dart';

class BdeDashboardScreen extends StatefulWidget {
  final VoidCallback? onCreateTask;
  final VoidCallback? onOpenNotifications;

  const BdeDashboardScreen({
    super.key,
    this.onCreateTask,
    this.onOpenNotifications,
  });

  @override
  State<BdeDashboardScreen> createState() => _BdeDashboardScreenState();
}

class _BdeDashboardScreenState extends State<BdeDashboardScreen> {
  final ApiService _apiService = ApiService();
  bool _isLoading = true;
  DateTime _selectedDate = DateTime.now();

  String _greeting = 'Welcome';
  Map<String, dynamic> _stats = {};
  List<dynamic> _pipeline = [];
  Map<String, dynamic> _dailyStats = {};
  List<TaskModel> _dailyTasks = [];
  List<TaskModel> _attentionTasks = [];
  List<TaskModel> _recentTasks = [];

  @override
  void initState() {
    super.initState();
    _fetchDashboard();
  }

  Future<void> _fetchDashboard() async {
    setState(() => _isLoading = true);
    final dateStr = DateFormat('yyyy-MM-dd').format(_selectedDate);
    final data = await _apiService.getBdeDashboard(date: dateStr);

    if (!mounted) return;
    setState(() {
      _isLoading = false;
      _greeting = data['greeting'] ?? 'Good day';
      _stats = data['stats'] ?? {};
      _pipeline = data['pipeline'] ?? [];
      _dailyStats = data['daily_stats'] ?? {};
      _dailyTasks = (data['daily_tasks'] as List<TaskModel>?) ?? [];
      _attentionTasks = (data['attention_tasks'] as List<TaskModel>?) ?? [];
      _recentTasks = (data['recent_tasks'] as List<TaskModel>?) ?? [];
    });
  }

  void _onDateSelected(DateTime dt) {
    setState(() => _selectedDate = dt);
    _fetchDashboard();
  }

  Future<void> _pickCustomDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate,
      firstDate: DateTime(2024),
      lastDate: DateTime.now().add(const Duration(days: 365)),
      builder: (context, child) {
        return Theme(
          data: Theme.of(context).copyWith(
            colorScheme: const ColorScheme.light(
              primary: AppColors.primary,
              onPrimary: Colors.white,
              onSurface: AppColors.textPrimary,
            ),
          ),
          child: child!,
        );
      },
    );
    if (picked != null) {
      _onDateSelected(picked);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading && _stats.isEmpty) {
      return const Scaffold(
        backgroundColor: AppColors.background,
        body: Center(
          child: CircularProgressIndicator(color: AppColors.primary),
        ),
      );
    }

    final unreadNotifs = _stats['unread_notifs'] ?? 0;
    final user = AuthService().currentUser;

    return Scaffold(
      backgroundColor: AppColors.background,
      body: RefreshIndicator(
        onRefresh: _fetchDashboard,
        color: AppColors.primary,
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // ─── Header: Greeting & Quick CTAs ──────────────────
              _buildHeader(user, unreadNotifs),
              const SizedBox(height: 16),

              // ─── KPI Grid (8 Cards) ─────────────────────────────
              _buildKpiGrid(),
              const SizedBox(height: 16),

              // ─── Ink Flow Pipeline Rail ─────────────────────────
              _buildPipelineRail(),
              const SizedBox(height: 16),

              // ─── Daily Task Log & Archive ───────────────────────
              _buildDailyTaskSection(),
              const SizedBox(height: 16),

              // ─── Needs Attention (Urgent / Overdue) ──────────────
              if (_attentionTasks.isNotEmpty) ...[
                _buildAttentionSection(),
                const SizedBox(height: 16),
              ],

              // ─── Recent Activity Tasks ──────────────────────────
              _buildRecentActivitySection(),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildHeader(dynamic user, int unreadNotifs) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
        boxShadow: const [
          BoxShadow(color: Color(0x05000000), blurRadius: 4, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _greeting,
                      style: const TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.w800,
                        color: AppColors.textPrimary,
                        letterSpacing: -0.3,
                      ),
                    ),
                    const SizedBox(height: 2),
                    const Text(
                      'Here is where your task pipeline stands right now.',
                      style: TextStyle(
                        fontSize: 11,
                        color: AppColors.textMuted,
                      ),
                    ),
                  ],
                ),
              ),
              // Notification Bell
              Stack(
                clipBehavior: Clip.none,
                children: [
                  IconButton(
                    icon: const Icon(Icons.notifications_none_rounded, color: AppColors.textSecondary),
                    onPressed: widget.onOpenNotifications,
                    tooltip: 'Notifications',
                  ),
                  if (unreadNotifs > 0)
                    Positioned(
                      top: 8,
                      right: 8,
                      child: Container(
                        padding: const EdgeInsets.all(4),
                        decoration: const BoxDecoration(
                          color: AppColors.error,
                          shape: BoxShape.circle,
                        ),
                        constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
                        child: Text(
                          '$unreadNotifs',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 9,
                            fontWeight: FontWeight.bold,
                          ),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ),
                ],
              ),
            ],
          ),
          const SizedBox(height: 12),
          const Divider(height: 1, color: AppColors.border),
          const SizedBox(height: 12),
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: AppColors.border),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.access_time_rounded, size: 13, color: AppColors.textMuted),
                    const SizedBox(width: 5),
                    Text(
                      DateFormat('dd MMM yyyy, hh:mm a').format(DateTime.now()),
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textSecondary,
                        fontFamily: 'monospace',
                      ),
                    ),
                  ],
                ),
              ),
              const Spacer(),
              ElevatedButton.icon(
                onPressed: () {
                  if (widget.onCreateTask != null) {
                    widget.onCreateTask!();
                  } else {
                    Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => const BdeCreateTaskScreen()),
                    ).then((_) => _fetchDashboard());
                  }
                },
                icon: const Icon(Icons.add_rounded, size: 16, color: Colors.white),
                label: const Text(
                  'New Task',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.white),
                ),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.primary,
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  elevation: 0,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildKpiGrid() {
    final total = _stats['total'] ?? 0;
    final newCount = _stats['new_count'] ?? 0;
    final inProgress = _stats['in_progress'] ?? 0;
    final rework = _stats['rework'] ?? 0;
    final urgent = _stats['urgent'] ?? 0;
    final overdue = _stats['overdue'] ?? 0;
    final completed = _stats['completed'] ?? 0;
    final totalWords = _stats['total_words'] ?? 0;
    final monthlyWords = _stats['monthly_words'] ?? 0;

    return GridView.count(
      crossAxisCount: 2,
      crossAxisSpacing: 10,
      mainAxisSpacing: 10,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      childAspectRatio: 1.6,
      children: [
        _buildKpiCard('TOTAL TASKS', '$total', Icons.inventory_2_outlined, const Color(0xFF71717A), const Color(0xFFF4F4F5)),
        _buildKpiCard('NEW', '$newCount', Icons.fiber_new_rounded, const Color(0xFF52525B), const Color(0xFFF4F4F5)),
        _buildKpiCard('WORKING', '$inProgress', Icons.bolt_rounded, const Color(0xFF1E40AF), const Color(0xFFE8EDFE)),
        _buildKpiCard('REWORK', '$rework', Icons.autorenew_rounded, const Color(0xFF9F1239), const Color(0xFFFDE8EC)),
        _buildKpiCard('URGENT', '$urgent', Icons.priority_high_rounded, const Color(0xFFD99321), const Color(0xFFFDF3E0)),
        _buildKpiCard('OVERDUE', '$overdue', Icons.schedule_rounded, const Color(0xFFE11D48), const Color(0xFFFDE8EC)),
        _buildKpiCard('COMPLETED', '$completed', Icons.task_alt_rounded, const Color(0xFF0F9D68), const Color(0xFFE2F6EE)),
        _buildWordCountCard(totalWords, monthlyWords),
      ],
    );
  }

  Widget _buildKpiCard(String label, String value, IconData icon, Color color, Color bg) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppColors.border),
        boxShadow: const [
          BoxShadow(color: Color(0x04000000), blurRadius: 3, offset: Offset(0, 1)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                label,
                style: const TextStyle(
                  fontSize: 9,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textMuted,
                  letterSpacing: 0.5,
                ),
              ),
              Container(
                width: 26,
                height: 26,
                decoration: BoxDecoration(
                  color: bg,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Icon(icon, size: 15, color: color),
              ),
            ],
          ),
          Text(
            value,
            style: TextStyle(
              fontSize: 20,
              fontWeight: FontWeight.w800,
              color: color == const Color(0xFF71717A) ? AppColors.textPrimary : color,
              fontFamily: 'monospace',
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildWordCountCard(int totalWords, int monthlyWords) {
    final numFmt = NumberFormat('#,###');
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppColors.border),
        boxShadow: const [
          BoxShadow(color: Color(0x04000000), blurRadius: 3, offset: Offset(0, 1)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text(
                'TOTAL WORDS',
                style: TextStyle(
                  fontSize: 9,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textMuted,
                  letterSpacing: 0.5,
                ),
              ),
              Container(
                width: 26,
                height: 26,
                decoration: BoxDecoration(
                  color: const Color(0xFFF4F4F5),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: const Icon(Icons.edit_note_rounded, size: 16, color: Color(0xFF71717A)),
              ),
            ],
          ),
          Text(
            numFmt.format(totalWords),
            style: const TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
              fontFamily: 'monospace',
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            decoration: BoxDecoration(
              color: const Color(0xFFE8EDFE),
              borderRadius: BorderRadius.circular(4),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  '${DateFormat('MMM').format(DateTime.now())}: ',
                  style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w600, color: Color(0xFF1E40AF)),
                ),
                Text(
                  '${numFmt.format(monthlyWords)}w',
                  style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: Color(0xFF1E40AF), fontFamily: 'monospace'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildPipelineRail() {
    if (_pipeline.isEmpty) return const SizedBox.shrink();

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text(
                'PIPELINE FLOW',
                style: TextStyle(
                  fontSize: 10,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textMuted,
                  letterSpacing: 0.5,
                ),
              ),
              Text(
                '${_stats['total'] ?? 0} tasks total',
                style: const TextStyle(fontSize: 10, color: AppColors.textMuted),
              ),
            ],
          ),
          const SizedBox(height: 10),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: SizedBox(
              height: 12,
              child: Row(
                children: _pipeline.map((item) {
                  final pct = (item['percentage'] as num?)?.toDouble() ?? 0.0;
                  if (pct <= 0) return const SizedBox.shrink();
                  final colorHex = (item['color'] as String?)?.replaceAll('#', '0xFF') ?? '0xFF3B6CF6';
                  final color = Color(int.tryParse(colorHex) ?? 0xFF3B6CF6);
                  return Flexible(
                    flex: (pct * 10).round(),
                    child: Container(color: color),
                  );
                }).toList(),
              ),
            ),
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 12,
            runSpacing: 6,
            children: _pipeline.map((item) {
              final colorHex = (item['color'] as String?)?.replaceAll('#', '0xFF') ?? '0xFF3B6CF6';
              final color = Color(int.tryParse(colorHex) ?? 0xFF3B6CF6);
              final count = item['count'] ?? 0;
              final label = item['label'] ?? '';

              return Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: 7,
                    height: 7,
                    decoration: BoxDecoration(color: color, shape: BoxShape.circle),
                  ),
                  const SizedBox(width: 4),
                  Text(
                    '$label ($count)',
                    style: const TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ],
              );
            }).toList(),
          ),
        ],
      ),
    );
  }

  Widget _buildDailyTaskSection() {
    final isToday = DateFormat('yyyy-MM-dd').format(_selectedDate) == DateFormat('yyyy-MM-dd').format(DateTime.now());
    final isYesterday = DateFormat('yyyy-MM-dd').format(_selectedDate) == DateFormat('yyyy-MM-dd').format(DateTime.now().subtract(const Duration(days: 1)));

    final booked = _dailyStats['total'] ?? 0;
    final newInQ = _dailyStats['new'] ?? 0;
    final inPipe = _dailyStats['working'] ?? 0;
    final dailyWords = _dailyStats['words'] ?? 0;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 32,
                height: 32,
                decoration: BoxDecoration(
                  color: const Color(0xFFFDF3E0),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: const Icon(Icons.calendar_today_rounded, size: 16, color: Color(0xFFD99321)),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Text(
                          'Daily Task Log',
                          style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800, color: AppColors.textPrimary),
                        ),
                        const SizedBox(width: 6),
                        Text(
                          '• ${DateFormat('dd MMM').format(_selectedDate)}',
                          style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AppColors.textMuted),
                        ),
                        if (isToday) ...[
                          const SizedBox(width: 4),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                            decoration: BoxDecoration(
                              color: const Color(0xFFE2F6EE),
                              borderRadius: BorderRadius.circular(4),
                            ),
                            child: const Text(
                              'TODAY',
                              style: TextStyle(fontSize: 8, fontWeight: FontWeight.w900, color: Color(0xFF065F46)),
                            ),
                          ),
                        ],
                      ],
                    ),
                    const Text(
                      'Tasks you booked on this date',
                      style: TextStyle(fontSize: 10, color: AppColors.textMuted),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          // Date quick selectors
          Row(
            children: [
              _buildDateChip('Today', isToday, () => _onDateSelected(DateTime.now())),
              const SizedBox(width: 6),
              _buildDateChip('Yesterday', isYesterday, () => _onDateSelected(DateTime.now().subtract(const Duration(days: 1)))),
              const SizedBox(width: 6),
              OutlinedButton.icon(
                onPressed: _pickCustomDate,
                icon: const Icon(Icons.date_range_rounded, size: 12, color: AppColors.textSecondary),
                label: Text(
                  DateFormat('dd/MM').format(_selectedDate),
                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AppColors.textSecondary),
                ),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
                  side: const BorderSide(color: AppColors.border),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
                  minimumSize: Size.zero,
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          // Daily Mini Stats
          Row(
            children: [
              _buildDailyMiniStat('Booked', '$booked', const Color(0xFF18181B)),
              const SizedBox(width: 6),
              _buildDailyMiniStat('New', '$newInQ', const Color(0xFF3B6CF6)),
              const SizedBox(width: 6),
              _buildDailyMiniStat('In Pipe', '$inPipe', const Color(0xFFD99321)),
              const SizedBox(width: 6),
              _buildDailyMiniStat('Words', NumberFormat('#,###').format(dailyWords), const Color(0xFF0F9D68)),
            ],
          ),
          if (_dailyTasks.isNotEmpty) ...[
            const SizedBox(height: 12),
            const Divider(height: 1, color: AppColors.border),
            const SizedBox(height: 8),
            ListView.separated(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              itemCount: _dailyTasks.length > 3 ? 3 : _dailyTasks.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                final t = _dailyTasks[i];
                return TaskCard(
                  task: t,
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => AdminTaskDetailScreen(taskId: t.id)),
                  ),
                );
              },
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildDateChip(String label, bool isSelected, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(6),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
          color: isSelected ? AppColors.primary : AppColors.surface,
          borderRadius: BorderRadius.circular(6),
          border: Border.all(color: isSelected ? AppColors.primary : AppColors.border),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.bold,
            color: isSelected ? Colors.white : AppColors.textSecondary,
          ),
        ),
      ),
    );
  }

  Widget _buildDailyMiniStat(String label, String value, Color color) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(6),
          border: Border.all(color: AppColors.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label.toUpperCase(),
              style: const TextStyle(fontSize: 8, fontWeight: FontWeight.w700, color: AppColors.textMuted),
            ),
            const SizedBox(height: 2),
            Text(
              value,
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w800,
                color: color,
                fontFamily: 'monospace',
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildAttentionSection() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0xFFFDE8EC)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.warning_amber_rounded, size: 16, color: Color(0xFFE11D48)),
              SizedBox(width: 6),
              Text(
                'NEEDS ATTENTION',
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                  color: Color(0xFFE11D48),
                  letterSpacing: 0.5,
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          const Text(
            'Tasks that are urgent or approaching / past deadline',
            style: TextStyle(fontSize: 10, color: AppColors.textMuted),
          ),
          const SizedBox(height: 10),
          ListView.separated(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: _attentionTasks.length > 3 ? 3 : _attentionTasks.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final t = _attentionTasks[i];
              return TaskCard(
                task: t,
                onTap: () => Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => AdminTaskDetailScreen(taskId: t.id)),
                ),
              );
            },
          ),
        ],
      ),
    );
  }

  Widget _buildRecentActivitySection() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'RECENT ACTIVITY',
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                  color: AppColors.textPrimary,
                  letterSpacing: 0.5,
                ),
              ),
              Icon(Icons.history_rounded, size: 16, color: AppColors.textMuted),
            ],
          ),
          const SizedBox(height: 4),
          const Text(
            'Latest tasks you booked in the system',
            style: TextStyle(fontSize: 10, color: AppColors.textMuted),
          ),
          const SizedBox(height: 10),
          if (_recentTasks.isEmpty)
            const Center(
              child: Padding(
                padding: EdgeInsets.symmetric(vertical: 20),
                child: Text('No tasks found.', style: TextStyle(fontSize: 12, color: AppColors.textMuted)),
              ),
            )
          else
            ListView.separated(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              itemCount: _recentTasks.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                final t = _recentTasks[i];
                return TaskCard(
                  task: t,
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => AdminTaskDetailScreen(taskId: t.id)),
                  ),
                );
              },
            ),
        ],
      ),
    );
  }
}

