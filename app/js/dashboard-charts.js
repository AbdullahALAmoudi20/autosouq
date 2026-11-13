/*
 * AutoSouq - charts on the manager dashboard, drawn with Chart.js.
 *
 * The figures come from the database through the data-chart attribute on the
 * first canvas, so there is no inline script on the page.
 *
 * @author  Fadel Al Saif (Admin Module)
 * @file    app/js/dashboard-charts.js
 * @task    Task 8 (extra: Chart.js library)
 */
(function () {
    'use strict';

    function init() {
        var salesCanvas = document.getElementById('sales-chart');
        var stockCanvas = document.getElementById('stock-chart');
        if (!salesCanvas || !stockCanvas || typeof Chart === 'undefined') {
            return;
        }

        var data = JSON.parse(salesCanvas.getAttribute('data-chart'));
        var colours = ['#5b8cff', '#e8a33d', '#3ecf8e', '#a78bfa', '#8892a6', '#ff6b6b'];

        Chart.defaults.font.family = 'Geist, system-ui, "Segoe UI", Arial, sans-serif';
        Chart.defaults.color = '#9ba6bc';
        Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.07)';

        new Chart(salesCanvas, {
            type: 'bar',
            data: {
                labels: data.brands,
                datasets: [{ label: 'Sales (SAR)', data: data.sales, backgroundColor: '#5b8cff', borderRadius: 6 }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function (v) { return v === 0 ? '0' : (v / 1000) + 'k'; } }, title: { display: true, text: 'SAR' } }
                }
            }
        });

        new Chart(stockCanvas, {
            type: 'doughnut',
            data: {
                labels: data.brands,
                datasets: [{ data: data.stock, backgroundColor: colours, borderColor: '#0c111c', borderWidth: 3 }]
            },
            options: { maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
