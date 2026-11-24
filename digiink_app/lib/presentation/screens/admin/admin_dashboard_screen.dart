import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:fl_chart/fl_chart.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/models/admin_stats_model.dart';
import 'package:digiink_app/data/models/task_model.dart';
import 'package:digiink_app/data/services/api_service.dart';
import 'package:digiink_app/data/services/auth_service.dart';
import 'package:digiink_app/presentation/screens/admin/admin_task_detail_screen.dart';
import 'package:digiink_app/presentation/screens/admin/admin_tasks_screen.dart';

class AdminDashboardScreen extends StatefulWidget {
  const AdminDashboardScreen({super.key});

  @override
  State<AdminDashboardScreen> createState() => _AdminDashboardScreenState();
}

class _AdminDashboardScreenState extends State<AdminDashboardScreen> {
  bool _isLoading = true;
  DateTime _selectedDate = DateTime.now();

  AdminStatsModel? _systemStats;
  DailyStatsModel? _dailyStats;
  List<Map<String, dynamic>> _topBde = [];
  List<Map<String, dynamic>> _topTeam = [];
  List<TaskModel> _recentTasks = [];
  List<TaskModel> _dailyTasks = [];

  @override
  void initState() {
    super.initState();
    _loadDashboard();
  }

  // Safe number formatter that NEVER throws isNegative on String values
  String formatNumber(dynamic value) {
    if (value == null) return '0';
    if (value is num) {
      return NumberFormat('#,###').format(value);
    }
    final parsed = num.tryParse(value.toString()) ?? 0;
    return NumberFormat('#,###').format(parsed);
  }

  int parseInt(dynamic value) {
    if (value == null) return 0;
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value.toString()) ?? 0;
  }

  Future<void> _loadDashboard() async {
    setState(() => _isLoading = true);
    final dateStr = DateFormat('yyyy-MM-dd').format(_selectedDate);
    final data = await ApiService().getAdminDashboard(date: dateStr);

    if (!mounted) return;
    setState(() {
      _systemStats = data['system_stats'];
      _dailyStats = data['daily_stats'];
      _topBde = data['top_bde'] ?? [];
      _topTeam = data['top_team'] ?? [];
      _recentTasks = data['recent_tasks'] ?? [];
      _dailyTasks = data['daily_tasks'] ?? [];
      _isLoading = false;
    });
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate,
      firstDate: DateTime(2024),
      lastDate: DateTime(2030),
      builder: (context, child) {
        return Theme(
          data: Theme.of(context).copyWith(
            colorScheme: const ColorScheme.light(
              primary: AppColors.secondary,
              onPrimary: Colors.white,
              surface: Colors.white,
              onSurface: AppColors.textPrimary,
            ),
          ),
          child: child!,
        );
      },
    );

    if (picked != null && picked != _selectedDate) {
      setState(() => _selectedDate = picked);
      _loadDashboard();
    }
  }

  void _setDateToday() {
    final now = DateTime.now();
    if (_selectedDate.year != now.year ||
        _selectedDate.month != now.month ||
        _selectedDate.day != now.day) {
      setState(() => _selectedDate = now);
      _loadDashboard();
    }
  }

  void _setDateYesterday() {
    final yesterday = DateTime.now().subtract(const Duration(days: 1));
    setState(() => _selectedDate = yesterday);
    _loadDashboard();
  }

  bool get _isToday {
    final now = DateTime.now();
    return _selectedDate.year == now.year &&
        _selectedDate.month == now.month &&
        _selectedDate.day == now.day;
  }

  bool get _isYesterday {
    final y = DateTime.now().subtract(const Duration(days: 1));
    return _selectedDate.year == y.year &&
        _selectedDate.month == y.month &&
        _selectedDate.day == y.day;
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthService().currentUser;
    final nowFormatted = DateFormat('dd MMM yyyy, hh:mm a').format(DateTime.now());

    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      body: SafeArea(
        child: RefreshIndicator(
          color: AppColors.primary,
          onRefresh: _loadDashboard,
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Top Welcome Header
                _buildHeader(user?.name ?? user?.username ?? 'Admin', nowFormatted),
                const SizedBox(height: 18),

                if (_isLoading) ...[
                  const SizedBox(height: 60),
                  const Center(child: CircularProgressIndicator(color: AppColors.primary)),
                  const SizedBox(height: 60),
                ] else ...[
                  // 1. Top 6 Stat Cards (matching web grid)
                  _buildSystemStatsGrid(),
                  const SizedBox(height: 20),

                  // 2. Daily Task Archive Section (Date-wise)
                  _buildDailyArchiveCard(),
                  const SizedBox(height: 20),

                  // 3. Visual Charts (Tasks pipeline & status donut)
                  _buildChartsSection(),
                  const SizedBox(height: 20),

                  // 4. Top Performing BDE Table
                  _buildTopBdeCard(),
                  const SizedBox(height: 20),

                  // 5. Top Operations Team Table
                  _buildTopTeamCard(),
                  const SizedBox(height: 20),

                  // 6. Recent System Activity Table
                  _buildRecentActivityCard(),
                  const SizedBox(height: 24),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  // ─── HEADER ─────────────────────────────────────────────────────────────
  Widget _buildHeader(String name, String timeStr) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  RichText(
                    text: TextSpan(
                      text: 'Welcome, ',
                      style: const TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w800,
                        color: Color(0xFF1E293B),
                        letterSpacing: -0.5,
                      ),
                      children: [
                        TextSpan(
                          text: name,
                          style: const TextStyle(
                            color: Color(0xFF2563EB),
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 3),
                  const Text(
                    'ADMIN DASHBOARD OVERVIEW',
                    style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w800,
                      color: Color(0xFF94A3B8),
                      letterSpacing: 1.2,
                    ),
                  ),
                ],
              ),
            ),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFE2E8F0)),
                boxShadow: const [
                  BoxShadow(color: Color(0x0A000000), blurRadius: 4, offset: Offset(0, 1)),
                ],
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.schedule, size: 14, color: Color(0xFF2563EB)),
                  const SizedBox(width: 5),
                  Text(
                    timeStr,
                    style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: Color(0xFF475569)),
                  ),
                ],
              ),
            ),
          ],
        ),
      ],
    );
  }

  // ─── 1. TOP 6 STAT CARDS (EXACT MATCH TO WEB) ───────────────────────────
  Widget _buildSystemStatsGrid() {
    final stats = _systemStats;
    final currMonth = DateFormat('MMM').format(DateTime.now());

    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: _buildWebStatCard(
                title: 'TOTAL USERS',
                value: '${stats?.totalUsers ?? 0}',
                borderColor: const Color(0xFF2563EB),
                textColor: const Color(0xFF1D4ED8),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _buildWebStatCard(
                title: 'TOTAL TASKS',
                value: '${stats?.totalTasks ?? 0}',
                borderColor: const Color(0xFF16A34A),
                textColor: const Color(0xFF15803D),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: _buildWebStatCard(
                title: 'IN PROGRESS',
                value: '${stats?.inProgress ?? 0}',
                borderColor: const Color(0xFFEAB308),
                textColor: const Color(0xFFCA8A04),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _buildWebStatCard(
                title: 'COMPLETED',
                value: '${stats?.completed ?? 0}',
                borderColor: const Color(0xFF9333EA),
                textColor: const Color(0xFF7E22CE),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: _buildWebStatCard(
                title: 'URGENT',
                value: '${stats?.urgent ?? 0}',
                borderColor: const Color(0xFFDC2626),
                textColor: const Color(0xFFB91C1C),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _buildWebWordsCard(
                totalWords: formatNumber(stats?.totalWords ?? 0),
                monthWords: formatNumber(stats?.monthlyWords ?? 0),
                monthName: currMonth,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildWebStatCard({
    required String title,
    required String value,
    required Color borderColor,
    required Color textColor,
  }) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFEEF2F6)),
        boxShadow: const [
          BoxShadow(color: Color(0x06000000), blurRadius: 8, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Container(
            height: 3,
            width: 36,
            decoration: BoxDecoration(
              color: borderColor,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            title,
            style: const TextStyle(
              fontSize: 10,
              fontWeight: FontWeight.w900,
              color: Color(0xFF94A3B8),
              letterSpacing: 1.0,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            value,
            style: TextStyle(
              fontSize: 24,
              fontWeight: FontWeight.w900,
              color: textColor,
              letterSpacing: -0.5,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildWebWordsCard({
    required String totalWords,
    required String monthWords,
    required String monthName,
  }) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFEEF2F6)),
        boxShadow: const [
          BoxShadow(color: Color(0x06000000), blurRadius: 8, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Container(
            height: 3,
            width: 36,
            decoration: BoxDecoration(
              color: const Color(0xFF4F46E5),
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(height: 6),
          const Text(
            'TOTAL WORDS',
            style: TextStyle(
              fontSize: 9,
              fontWeight: FontWeight.w900,
              color: Color(0xFF94A3B8),
              letterSpacing: 0.8,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            totalWords,
            style: const TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.w900,
              color: Color(0xFF4338CA),
              letterSpacing: -0.5,
            ),
          ),
          const SizedBox(height: 4),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            decoration: BoxDecoration(
              color: const Color(0xFFEEF2FF),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Text(
              '$monthName: $monthWords',
              style: const TextStyle(
                fontSize: 9,
                fontWeight: FontWeight.w800,
                color: Color(0xFF4F46E5),
              ),
            ),
          ),
        ],
      ),
    );
  }

  // ─── 2. DAILY TASK ARCHIVE (DATE-WISE BREAKDOWN) ────────────────────────
  Widget _buildDailyArchiveCard() {
    final daily = _dailyStats;
    final dateDisplay = DateFormat('dd MMM yyyy').format(_selectedDate);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFEEF2F6)),
        boxShadow: const [
          BoxShadow(color: Color(0x06000000), blurRadius: 10, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Section Title & Date Controls
          Row(
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: const Color(0xFF0F172A),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Icon(Icons.calendar_today, color: Colors.white, size: 16),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Text(
                          'System Daily Task Archive',
                          style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800, color: Color(0xFF1E293B)),
                        ),
                        if (_isToday) ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(
                              color: const Color(0xFFD1FAE5),
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: const Text(
                              'TODAY',
                              style: TextStyle(fontSize: 8, fontWeight: FontWeight.w900, color: Color(0xFF065F46)),
                            ),
                          ),
                        ],
                      ],
                    ),
                    Text(
                      dateDisplay,
                      style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: Color(0xFF94A3B8)),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),

          // Quick Filter Buttons (Today, Yesterday, Pick Date)
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                _buildDateButton('Today', _isToday, _setDateToday),
                const SizedBox(width: 6),
                _buildDateButton('Yesterday', _isYesterday, _setDateYesterday),
                const SizedBox(width: 6),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    visualDensity: VisualDensity.compact,
                    side: const BorderSide(color: Color(0xFFCBD5E1)),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  ),
                  icon: const Icon(Icons.edit_calendar, size: 13, color: Color(0xFF475569)),
                  label: const Text('Pick Date', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Color(0xFF475569))),
                  onPressed: _pickDate,
                ),
                const SizedBox(width: 6),
                ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF4F46E5),
                    visualDensity: VisualDensity.compact,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  ),
                  icon: const Icon(Icons.arrow_forward, size: 13, color: Colors.white),
                  label: const Text('Full Daily Hub', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.white)),
                  onPressed: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => const AdminTasksScreen(),
                      ),
                    );
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),

          // 4 Metric Tiles (Total Booked, Active, Completed, Word Count)
          Row(
            children: [
              _buildDailyPill('TOTAL BOOKED', '${daily?.total ?? 0}', 'Tasks', const Color(0xFF0F172A)),
              const SizedBox(width: 6),
              _buildDailyPill('ACTIVE', '${daily?.working ?? 0}', 'Tasks', const Color(0xFFD97706)),
              const SizedBox(width: 6),
              _buildDailyPill('COMPLETED', '${daily?.completed ?? 0}', 'Delivered', const Color(0xFF16A34A)),
              const SizedBox(width: 6),
              _buildDailyPill('WORD COUNT', formatNumber(daily?.words ?? 0), 'Words', const Color(0xFF4F46E5)),
            ],
          ),
          const SizedBox(height: 14),

          // Daily Tasks List
          if (_dailyTasks.isEmpty)
            Container(
              padding: const EdgeInsets.symmetric(vertical: 20),
              alignment: Alignment.center,
              child: const Column(
                children: [
                  Icon(Icons.event_busy, color: Color(0xFFCBD5E1), size: 30),
                  SizedBox(height: 4),
                  Text(
                    'No tasks recorded for this date',
                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: Color(0xFF94A3B8)),
                  ),
                ],
              ),
            )
          else ...[
            ..._dailyTasks.take(5).map((t) => _buildDailyTaskItem(t)),
            if (_dailyTasks.length > 5) ...[
              const SizedBox(height: 6),
              Center(
                child: TextButton(
                  onPressed: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => const AdminTasksScreen()),
                    );
                  },
                  child: Text(
                    'View all ${_dailyTasks.length} tasks for this date →',
                    style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Color(0xFF4F46E5)),
                  ),
                ),
              ),
            ],
          ],
        ],
      ),
    );
  }

  Widget _buildDateButton(String label, bool isSelected, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
        decoration: BoxDecoration(
          color: isSelected ? const Color(0xFF0F172A) : const Color(0xFFF1F5F9),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.bold,
            color: isSelected ? Colors.white : const Color(0xFF475569),
          ),
        ),
      ),
    );
  }

  Widget _buildDailyPill(String title, String val, String subtitle, Color color) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 6),
        decoration: BoxDecoration(
          color: const Color(0xFFF8FAFC),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: const Color(0xFFEEF2F6)),
        ),
        child: Column(
          children: [
            Text(
              title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontSize: 8, fontWeight: FontWeight.w900, color: color),
            ),
            const SizedBox(height: 2),
            Text(
              val,
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w900, color: color),
            ),
            Text(
              subtitle,
              style: const TextStyle(fontSize: 8, fontWeight: FontWeight.w600, color: Color(0xFF94A3B8)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildDailyTaskItem(TaskModel task) {
    final lastTwo = task.taskKey.length >= 2 ? task.taskKey.substring(task.taskKey.length - 2) : '00';

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFEEF2F6)),
      ),
      child: Row(
        children: [
          Container(
            width: 28,
            height: 28,
            decoration: BoxDecoration(
              color: const Color(0xFFE2E8F0),
              borderRadius: BorderRadius.circular(8),
            ),
            alignment: Alignment.center,
            child: Text(
              lastTwo,
              style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: Color(0xFF1E293B)),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  task.taskName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Color(0xFF1E293B)),
                ),
                Text(
                  '${task.taskKey} · ${formatNumber(task.wordCount)} words · ${task.expertName ?? "Queue"}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w600, color: Color(0xFF94A3B8)),
                ),
              ],
            ),
          ),
          const SizedBox(width: 6),
          _buildStatusChip(task.status),
        ],
      ),
    );
  }

  Widget _buildStatusChip(String status) {
    Color bg;
    Color fg;
    switch (status.toLowerCase()) {
      case 'completed':
        bg = const Color(0xFFECFDF5);
        fg = const Color(0xFF059669);
        break;
      case 'in_progress':
        bg = const Color(0xFFEFF6FF);
        fg = const Color(0xFF2563EB);
        break;
      case 'rework':
        bg = const Color(0xFFFEF2F2);
        fg = const Color(0xFFDC2626);
        break;
      default:
        bg = const Color(0xFFF1F5F9);
        fg = const Color(0xFF64748B);
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(6)),
      child: Text(
        status.replaceAll('_', ' ').toUpperCase(),
        style: TextStyle(fontSize: 8, fontWeight: FontWeight.w800, color: fg),
      ),
    );
  }

  // ─── 3. VISUAL CHARTS (APEX CHARTS ON MOBILE VIA FL_CHART) ───────────────
  Widget _buildChartsSection() {
    final stats = _systemStats;
    final total = (stats?.totalTasks ?? 0).toDouble();
    final completed = (stats?.completed ?? 0).toDouble();
    final working = (stats?.inProgress ?? 0).toDouble();
    final urgent = (stats?.urgent ?? 0).toDouble();

    final maxBar = [total, completed, working, urgent, 10.0].reduce((a, b) => a > b ? a : b);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFEEF2F6)),
        boxShadow: const [
          BoxShadow(color: Color(0x06000000), blurRadius: 10, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.bar_chart_rounded, size: 18, color: Color(0xFF2563EB)),
              SizedBox(width: 8),
              Text(
                'Task Pipeline & Distribution',
                style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800, color: Color(0xFF1E293B)),
              ),
            ],
          ),
          const SizedBox(height: 16),
          SizedBox(
            height: 180,
            child: BarChart(
              BarChartData(
                alignment: BarChartAlignment.spaceAround,
                maxY: maxBar + (maxBar * 0.15),
                titlesData: FlTitlesData(
                  show: true,
                  bottomTitles: AxisTitles(
                    sideTitles: SideTitles(
                      showTitles: true,
                      getTitlesWidget: (val, meta) {
                        const labels = ['Total', 'Completed', 'Progress', 'Urgent'];
                        if (val.toInt() >= 0 && val.toInt() < labels.length) {
                          return Padding(
                            padding: const EdgeInsets.only(top: 6),
                            child: Text(
                              labels[val.toInt()],
                              style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w700, color: Color(0xFF64748B)),
                            ),
                          );
                        }
                        return const SizedBox();
                      },
                    ),
                  ),
                  leftTitles: AxisTitles(
                    sideTitles: SideTitles(
                      showTitles: true,
                      reservedSize: 26,
                      getTitlesWidget: (val, meta) {
                        return Text(
                          '${val.toInt()}',
                          style: const TextStyle(fontSize: 9, color: Color(0xFF94A3B8)),
                        );
                      },
                    ),
                  ),
                  topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                  rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                ),
                gridData: FlGridData(
                  show: true,
                  drawVerticalLine: false,
                  getDrawingHorizontalLine: (val) => FlLine(color: const Color(0xFFF1F5F9), strokeWidth: 1),
                ),
                borderData: FlBorderData(show: false),
                barGroups: [
                  _makeBar(0, total, const Color(0xFF2563EB)),
                  _makeBar(1, completed, const Color(0xFF16A34A)),
                  _makeBar(2, working, const Color(0xFFEAB308)),
                  _makeBar(3, urgent, const Color(0xFFDC2626)),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  BarChartGroupData _makeBar(int x, double y, Color color) {
    return BarChartGroupData(
      x: x,
      barRods: [
        BarChartRodData(
          toY: y,
          color: color,
          width: 20,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(6)),
        ),
      ],
    );
  }

  // ─── 4. TOP BDE TABLE ───────────────────────────────────────────────────
  Widget _buildTopBdeCard() {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFEEF2F6)),
        boxShadow: const [
          BoxShadow(color: Color(0x06000000), blurRadius: 10, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: const BoxDecoration(
              color: Color(0xFFEFF6FF),
              borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
            ),
            child: const Row(
              children: [
                Icon(Icons.leaderboard_rounded, size: 18, color: Color(0xFF2563EB)),
                SizedBox(width: 8),
                Text(
                  'Top Performing BDE',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w900, color: Color(0xFF1E293B)),
                ),
              ],
            ),
          ),
          if (_topBde.isEmpty)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Text('No BDE records yet', style: TextStyle(fontSize: 11, color: Color(0xFF94A3B8))),
            )
          else ...[
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Row(
                children: const [
                  Expanded(flex: 3, child: Text('NAME', style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: Color(0xFF94A3B8)))),
                  Expanded(flex: 2, child: Text('TASKS', textAlign: TextAlign.center, style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: Color(0xFF94A3B8)))),
                  Expanded(flex: 2, child: Text('WORDS', textAlign: TextAlign.right, style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: Color(0xFF94A3B8)))),
                ],
              ),
            ),
            const Divider(height: 1, color: Color(0xFFF1F5F9)),
            ..._topBde.map((b) => _buildTopRow(
                  b['name'] ?? b['username'] ?? 'User',
                  '${parseInt(b['total_tasks'])}',
                  formatNumber(b['words']),
                  const Color(0xFF2563EB),
                )),
          ],
        ],
      ),
    );
  }

  // ─── 5. TOP OPERATIONS TEAM TABLE ───────────────────────────────────────
  Widget _buildTopTeamCard() {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFEEF2F6)),
        boxShadow: const [
          BoxShadow(color: Color(0x06000000), blurRadius: 10, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: const BoxDecoration(
              color: Color(0xFFF0FDF4),
              borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
            ),
            child: const Row(
              children: [
                Icon(Icons.military_tech_rounded, size: 18, color: Color(0xFF16A34A)),
                SizedBox(width: 8),
                Text(
                  'Top Operations Team',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w900, color: Color(0xFF1E293B)),
                ),
              ],
            ),
          ),
          if (_topTeam.isEmpty)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Text('No team records yet', style: TextStyle(fontSize: 11, color: Color(0xFF94A3B8))),
            )
          else ...[
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Row(
                children: const [
                  Expanded(flex: 3, child: Text('NAME', style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: Color(0xFF94A3B8)))),
                  Expanded(flex: 2, child: Text('COMPLETED', textAlign: TextAlign.center, style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: Color(0xFF94A3B8)))),
                  Expanded(flex: 2, child: Text('WORDS', textAlign: TextAlign.right, style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: Color(0xFF94A3B8)))),
                ],
              ),
            ),
            const Divider(height: 1, color: Color(0xFFF1F5F9)),
            ..._topTeam.map((t) => _buildTopRow(
                  t['name'] ?? t['username'] ?? 'User',
                  '${parseInt(t['completed_tasks'])}',
                  formatNumber(t['words']),
                  const Color(0xFF16A34A),
                )),
          ],
        ],
      ),
    );
  }

  Widget _buildTopRow(String name, String tasks, String words, Color accentColor) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: const BoxDecoration(
        border: Border(bottom: BorderSide(color: Color(0xFFF8FAFC))),
      ),
      child: Row(
        children: [
          Expanded(
            flex: 3,
            child: Text(
              name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Color(0xFF334155)),
            ),
          ),
          Expanded(
            flex: 2,
            child: Text(
              tasks,
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 12, fontWeight: FontWeight.w900, color: accentColor),
            ),
          ),
          Expanded(
            flex: 2,
            child: Text(
              words,
              textAlign: TextAlign.right,
              style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w900, color: Color(0xFF4F46E5)),
            ),
          ),
        ],
      ),
    );
  }

  // ─── 6. RECENT SYSTEM ACTIVITY ──────────────────────────────────────────
  Widget _buildRecentActivityCard() {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFEEF2F6)),
        boxShadow: const [
          BoxShadow(color: Color(0x06000000), blurRadius: 10, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: const BoxDecoration(
              color: Color(0xFFF8FAFC),
              borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
            ),
            child: const Row(
              children: [
                Icon(Icons.history_rounded, size: 18, color: Color(0xFF64748B)),
                SizedBox(width: 8),
                Text(
                  'Recent System Activity',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w900, color: Color(0xFF1E293B)),
                ),
              ],
            ),
          ),
          if (_recentTasks.isEmpty)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Text('No recent activity', style: TextStyle(fontSize: 11, color: Color(0xFF94A3B8))),
            )
          else
            ListView.separated(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              itemCount: _recentTasks.length,
              separatorBuilder: (_, __) => const Divider(height: 1, color: Color(0xFFF1F5F9)),
              itemBuilder: (ctx, index) {
                final task = _recentTasks[index];
                return ListTile(
                  dense: true,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                  title: Row(
                    children: [
                      Text(
                        task.taskKey,
                        style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w900, color: Color(0xFF1E293B)),
                      ),
                      const SizedBox(width: 8),
                      _buildStatusChip(task.status),
                    ],
                  ),
                  subtitle: Text(
                    task.taskName,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 10, color: Color(0xFF64748B)),
                  ),
                  trailing: Text(
                    task.deadline.length >= 10 ? task.deadline.substring(0, 10) : task.deadline,
                    style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Color(0xFFEF4444)),
                  ),
                  onTap: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => AdminTaskDetailScreen(taskId: task.id),
                      ),
                    );
                  },
                );
              },
            ),
        ],
      ),
    );
  }
}
