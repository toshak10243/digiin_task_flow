import 'package:flutter/material.dart';
import 'package:fl_chart/fl_chart.dart';
import 'package:intl/intl.dart';
import 'package:digiink_app/core/constants/app_colors.dart';

class AdminReportsScreen extends StatefulWidget {
  const AdminReportsScreen({super.key});

  @override
  State<AdminReportsScreen> createState() => _AdminReportsScreenState();
}

class _AdminReportsScreenState extends State<AdminReportsScreen> {
  String _selectedRange = 'This Month';
  final numberFormat = NumberFormat('#,###');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: const Text('Reports & Analytics'),
        actions: [
          IconButton(
            icon: const Icon(Icons.file_download_outlined, color: AppColors.primary),
            tooltip: 'Export Report',
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  backgroundColor: AppColors.secondary,
                  content: Row(
                    children: const [
                      Icon(Icons.check_circle_outline, color: AppColors.success, size: 20),
                      SizedBox(width: 8),
                      Text('Monthly Performance Report exported successfully!'),
                    ],
                  ),
                ),
              );
            },
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Time range chips
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: ['Today', 'This Week', 'This Month', 'Last Quarter'].map((range) {
                  final isSelected = _selectedRange == range;
                  return Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: Text(range),
                      selected: isSelected,
                      selectedColor: AppColors.primary,
                      labelStyle: TextStyle(
                        color: isSelected ? Colors.white : AppColors.textSecondary,
                        fontWeight: FontWeight.w600,
                        fontSize: 13,
                      ),
                      backgroundColor: AppColors.surface,
                      side: BorderSide(
                        color: isSelected ? AppColors.primary : AppColors.border,
                      ),
                      onSelected: (val) {
                        if (val) setState(() => _selectedRange = range);
                      },
                    ),
                  );
                }).toList(),
              ),
            ),
            const SizedBox(height: 16),

            // Highlights Banner
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [AppColors.primary, Color(0xFF6366F1)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(16),
                boxShadow: [
                  BoxShadow(
                    color: AppColors.primary.withOpacity(0.25),
                    blurRadius: 16,
                    offset: const Offset(0, 6),
                  ),
                ],
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text(
                        'Total Word Volume',
                        style: TextStyle(color: Colors.white70, fontSize: 13, fontWeight: FontWeight.w500),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                        decoration: BoxDecoration(
                          color: Colors.white.withOpacity(0.2),
                          borderRadius: BorderRadius.circular(20),
                        ),
                        child: const Row(
                          children: [
                            Icon(Icons.trending_up_rounded, color: Colors.white, size: 14),
                            SizedBox(width: 4),
                            Text('+18.4%', style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    '${numberFormat.format(1245000)} Words',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 26,
                      fontWeight: FontWeight.w800,
                      letterSpacing: -0.5,
                    ),
                  ),
                  const SizedBox(height: 12),
                  const Divider(color: Colors.white24, height: 1),
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      _buildWhiteStat('Tasks Completed', '312 / 429'),
                      _buildWhiteStat('Avg Turnaround', '28.4 Hrs'),
                      _buildWhiteStat('Rework Rate', '1.8%'),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),

            // Weekly Throughput Bar Chart
            const Text(
              'Weekly Production Volume (Words)',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(height: 12),
            Container(
              height: 220,
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: AppColors.border),
              ),
              child: BarChart(
                BarChartData(
                  alignment: BarChartAlignment.spaceAround,
                  maxY: 60,
                  barTouchData: BarTouchData(enabled: true),
                  titlesData: FlTitlesData(
                    show: true,
                    bottomTitles: AxisTitles(
                      sideTitles: SideTitles(
                        showTitles: true,
                        getTitlesWidget: (val, meta) {
                          const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                          if (val.toInt() >= 0 && val.toInt() < days.length) {
                            return Padding(
                              padding: const EdgeInsets.only(top: 6),
                              child: Text(
                                days[val.toInt()],
                                style: const TextStyle(fontSize: 11, color: AppColors.textMuted, fontWeight: FontWeight.w600),
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
                        reservedSize: 32,
                        getTitlesWidget: (val, meta) {
                          return Text(
                            '${val.toInt()}k',
                            style: const TextStyle(fontSize: 10, color: AppColors.textMuted),
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
                    horizontalInterval: 15,
                    getDrawingHorizontalLine: (val) => FlLine(color: AppColors.border, strokeWidth: 1),
                  ),
                  borderData: FlBorderData(show: false),
                  barGroups: [
                    _makeBarGroup(0, 35),
                    _makeBarGroup(1, 42),
                    _makeBarGroup(2, 48),
                    _makeBarGroup(3, 52),
                    _makeBarGroup(4, 40),
                    _makeBarGroup(5, 28),
                    _makeBarGroup(6, 18),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 24),

            // Role Contribution Breakdown
            const Text(
              'Team Task Distribution',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: AppColors.border),
              ),
              child: Column(
                children: [
                  _buildProgressRow('Operations Team (Writers)', 0.55, '236 Tasks (55%)', AppColors.primary),
                  const SizedBox(height: 14),
                  _buildProgressRow('Business Dev (BDE Inflow)', 0.28, '120 Tasks (28%)', AppColors.bdeColor),
                  const SizedBox(height: 14),
                  _buildProgressRow('Quality Check (QC Review)', 0.12, '51 Tasks (12%)', AppColors.qcColor),
                  const SizedBox(height: 14),
                  _buildProgressRow('Operations Managers (Allocated)', 0.05, '22 Tasks (5%)', AppColors.managerColor),
                ],
              ),
            ),
            const SizedBox(height: 24),

            // Top Deliverers Leaderboard
            const Text(
              'Top Production Leaders',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: AppColors.textPrimary,
              ),
            ),
            const SizedBox(height: 12),
            _buildLeaderCard(
              rank: '1',
              name: 'Toshak Sharma',
              role: 'Operations Team Lead',
              stats: '48 Tasks • 142,000 Words',
              badgeColor: const Color(0xFFF59E0B),
            ),
            const SizedBox(height: 8),
            _buildLeaderCard(
              rank: '2',
              name: 'Pearl Sharma',
              role: 'Senior BDE',
              stats: '42 Tasks • 128,000 Words',
              badgeColor: const Color(0xFF94A3B8),
            ),
            const SizedBox(height: 8),
            _buildLeaderCard(
              rank: '3',
              name: 'Parth Patel',
              role: 'Senior Academic Expert',
              stats: '39 Tasks • 98,000 Words',
              badgeColor: const Color(0xFFB45309),
            ),
            const SizedBox(height: 32),
          ],
        ),
      ),
    );
  }

  BarChartGroupData _makeBarGroup(int x, double y) {
    return BarChartGroupData(
      x: x,
      barRods: [
        BarChartRodData(
          toY: y,
          color: AppColors.primary,
          width: 18,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(6)),
          backDrawRodData: BackgroundBarChartRodData(
            show: true,
            toY: 60,
            color: AppColors.primary.withOpacity(0.08),
          ),
        ),
      ],
    );
  }

  Widget _buildWhiteStat(String label, String value) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(color: Colors.white70, fontSize: 11)),
        const SizedBox(height: 2),
        Text(value, style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.bold)),
      ],
    );
  }

  Widget _buildProgressRow(String label, double ratio, String countText, Color color) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(label, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.textPrimary)),
            Text(countText, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w500, color: AppColors.textSecondary)),
          ],
        ),
        const SizedBox(height: 6),
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(
            value: ratio,
            minHeight: 7,
            backgroundColor: color.withOpacity(0.12),
            valueColor: AlwaysStoppedAnimation<Color>(color),
          ),
        ),
      ],
    );
  }

  Widget _buildLeaderCard({
    required String rank,
    required String name,
    required String role,
    required String stats,
    required Color badgeColor,
  }) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 14,
            backgroundColor: badgeColor,
            child: Text(
              rank,
              style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(name, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: AppColors.textPrimary)),
                Text(role, style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
              ],
            ),
          ),
          Text(
            stats,
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.primary),
          ),
        ],
      ),
    );
  }
}
