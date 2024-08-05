<?php
/*
Plugin Name: Monthly Cohort Retention Rate Dashboard Widget
Description: Adds a dashboard widget to display monthly cohort retention rates for the last three months in a tabular format and generate a combined graph.
Version: 1.0
Author: Your Name
*/

// Enqueue Chart.js library
function mcw_enqueue_chartjs_library()
{
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), null, true);
}
add_action('admin_enqueue_scripts', 'mcw_enqueue_chartjs_library');

// Track user login timestamps
function mcw_track_user_login_timestamp($user_login, $user)
{
    $user_id = $user->ID;
    $current_time = current_time('mysql');
    add_user_meta($user_id, 'rrdw_login_timestamp', $current_time);
}
add_action('wp_login', 'mcw_track_user_login_timestamp', 10, 2);

// Add Dashboard Widgets
function mcw_add_retention_rate_dashboard_widgets()
{
    wp_add_dashboard_widget(
        'mcw_retention_rate_table_widget',
        'User Retention Rate Table',
        'mcw_render_retention_rate_table_dashboard_widget'
    );
    wp_add_dashboard_widget(
        'mcw_retention_rate_graph_widget',
        'User Retention Rate Combined Graph',
        'mcw_render_retention_rate_graph_dashboard_widget'
    );
}
add_action('wp_dashboard_setup', 'mcw_add_retention_rate_dashboard_widgets');

// Render Retention Rate Table Widget
function mcw_render_retention_rate_table_dashboard_widget()
{
    $data = mcw_get_retention_data();

    echo '<table>';
    echo '<tr><th>Month</th><th>Registered</th>';
    foreach ($data['monthly_labels'] as $label) {
        echo "<th>{$label}</th>";
    }
    echo '</tr>';

    foreach ($data['datasets'] as $dataset) {
        echo '<tr>';
        echo "<td>{$dataset['label']}</td>";
        echo "<td>{$dataset['registered']}</td>";
        for ($i = 0; $i < count($data['monthly_labels']); $i++) {
            echo "<td>" . (isset($dataset['logged_in'][$i]) ? $dataset['logged_in'][$i] : 'N/A') . "</td>";
        }
        echo '</tr>';
    }
    echo '</table>';
}

// Render Combined Retention Rate Graph Widget
function mcw_render_retention_rate_graph_dashboard_widget()
{
    $data = mcw_get_retention_data();

    echo '<canvas id="combinedRetentionChart" width="400" height="200"></canvas>';
?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var ctx = document.getElementById('combinedRetentionChart').getContext('2d');
            var combinedRetentionChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($data['monthly_labels']); ?>,
                    datasets: [
                        <?php foreach ($data['datasets'] as $dataset) : ?> {
                                label: '<?php echo $dataset['label']; ?> Cohort',
                                data: <?php echo json_encode($dataset['logged_in']); ?>,
                                borderColor: '<?php echo $dataset['borderColor']; ?>',
                                fill: false
                            },
                        <?php endforeach; ?>
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });
        });
    </script>
<?php
}

// Helper Functions
function mcw_get_login_timestamps($user_id)
{
    return array_unique(array_map(function ($timestamp) {
        return date('Y-m', strtotime($timestamp));
    }, get_user_meta($user_id, 'rrdw_login_timestamp', false)));
}

function mcw_calculate_monthly_retention($timestamps, $start_date, $months)
{
    $logged_in_counts = [];

    for ($month = 0; $month < $months; $month++) {
        $month_start = date('Y-m', strtotime("+$month month", strtotime($start_date)));
        $returning_count = count(array_filter($timestamps, function ($timestamp) use ($month_start) {
            return $timestamp === $month_start;
        }));

        $logged_in_counts[] = $returning_count;
    }

    return $logged_in_counts;
}

function mcw_get_retention_data()
{
    $users = get_users();
    $data = [
        'monthly_labels' => [],
        'datasets' => [],
    ];

    $current_time = current_time('timestamp');
    $current_month = date('Y-m-01', $current_time);
    $one_month_ago = date('Y-m-01', strtotime('-1 month', $current_time));
    $two_months_ago = date('Y-m-01', strtotime('-2 months', $current_time));
    $three_months_ago = date('Y-m-01', strtotime('-3 months', $current_time));

    $months = [$three_months_ago, $two_months_ago, $one_month_ago];
    $month_names = [date('F', strtotime($three_months_ago)), date('F', strtotime($two_months_ago)), date('F', strtotime($one_month_ago))];

    // Add the monthly labels
    $data['monthly_labels'] = $month_names;

    // Colors for the cohorts
    $colors = [
        'rgba(75, 192, 192, 1)',
        'rgba(255, 99, 132, 1)',
        'rgba(54, 162, 235, 1)'
    ];

    foreach ($months as $index => $month) {
        $start_of_month = date('Y-m-01', strtotime($month));
        $end_of_month = date('Y-m-t', strtotime($month));

        $cohort_users = array_filter($users, function ($user) use ($start_of_month, $end_of_month) {
            $registration_date = strtotime($user->user_registered);
            return $registration_date >= strtotime($start_of_month) && $registration_date <= strtotime($end_of_month);
        });

        $initial_count = count($cohort_users);

        // Get login timestamps for all cohort users
        $all_timestamps = [];
        foreach ($cohort_users as $user) {
            $timestamps = mcw_get_login_timestamps($user->ID);
            $all_timestamps = array_merge($all_timestamps, $timestamps);
        }

        // Calculate the logged in counts
        $logged_in_counts = mcw_calculate_monthly_retention($all_timestamps, $start_of_month, 3);

        // Trim the counts to include only months following registration
        if ($initial_count > 0) {
            $logged_in_counts = array_slice($logged_in_counts, $index);
        } else {
            $logged_in_counts = array_fill(0, 3, 'N/A');
        }

        // Log the counts for debugging
        error_log("Cohort: {$month_names[$index]}, Registered: {$initial_count}, Logins: " . print_r($logged_in_counts, true));

        $data['datasets'][] = [
            'label' => $month_names[$index],
            'registered' => $initial_count,
            'logged_in' => $logged_in_counts,
            'borderColor' => $colors[$index % 3],
            'fill' => false
        ];
    }

    return $data;
}
?>