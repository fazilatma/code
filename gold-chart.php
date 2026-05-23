<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Gold Price Chart</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            color: white;
            padding: 30px 20px;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            background: linear-gradient(90deg, #ffd700, #ffed4a, #ffd700);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .header p {
            color: #a0a0a0;
            font-size: 1.1rem;
        }
        .price-display {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 30px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .current-price {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px 40px;
            text-align: center;
            border: 1px solid rgba(255, 215, 0, 0.3);
        }
        .current-price .label {
            color: #a0a0a0;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }
        .current-price .price {
            font-size: 3rem;
            font-weight: bold;
            color: #ffd700;
        }
        .current-price .currency {
            font-size: 1.5rem;
            color: #ffd700;
        }
        .price-change {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 20px 30px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .price-change .change-value {
            font-size: 1.8rem;
            font-weight: bold;
        }
        .price-change .change-percent {
            font-size: 1.2rem;
            margin-top: 5px;
        }
        .positive {
            color: #4caf50;
        }
        .negative {
            color: #f44336;
        }
        .chart-container {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .chart-wrapper {
            position: relative;
            height: 400px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .stat-card .label {
            color: #a0a0a0;
            font-size: 0.85rem;
            margin-bottom: 8px;
        }
        .stat-card .value {
            color: #ffd700;
            font-size: 1.4rem;
            font-weight: bold;
        }
        .last-updated {
            text-align: center;
            color: #666;
            margin-top: 20px;
            font-size: 0.9rem;
        }
        .api-source {
            text-align: center;
            color: #4caf50;
            font-size: 0.85rem;
            margin-top: 10px;
        }
        .api-source.fallback {
            color: #ff9800;
        }
        .api-status {
            text-align: center;
            color: #666;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 400px;
            color: #ffd700;
            font-size: 1.2rem;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .live-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            background: #4caf50;
            border-radius: 50%;
            margin-right: 8px;
            animation: pulse 1.5s infinite;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🪙 Live Gold Price</h1>
            <p><span class="live-indicator"></span>Real-time gold price tracking</p>
        </div>
        
        <div class="price-display">
            <div class="current-price">
                <div class="label">Current Price (per ounce)</div>
                <div class="price">$<span id="currentPrice">---</span></div>
            </div>
            <div class="price-change">
                <div class="label">24h Change</div>
                <div class="change-value" id="changeValue">---</div>
                <div class="change-percent" id="changePercent">---</div>
            </div>
        </div>
        
        <div class="chart-container">
            <div class="chart-wrapper">
                <canvas id="goldChart"></canvas>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="label">24h High</div>
                <div class="value" id="highPrice">---</div>
            </div>
            <div class="stat-card">
                <div class="label">24h Low</div>
                <div class="value" id="lowPrice">---</div>
            </div>
            <div class="stat-card">
                <div class="label">Average</div>
                <div class="value" id="avgPrice">---</div>
            </div>
            <div class="stat-card">
                <div class="label">Data Points</div>
                <div class="value" id="dataPoints">---</div>
            </div>
        </div>
        
        <div class="last-updated">
            Last updated: <span id="lastUpdated">---</span>
        </div>
        <div class="api-source" id="apiSource">
            Data source: <span id="sourceName">---</span>
        </div>
        <div class="api-status" id="apiStatus"></div>
    </div>
    
    <script>
        let goldChart = null;
        let updateInterval = null;
        
        async function fetchGoldData() {
            try {
                const response = await fetch('gold-api.php');
                const data = await response.json();
                updateChart(data);
            } catch (error) {
                console.error('Error fetching gold data:', error);
            }
        }
        
        function updateChart(data) {
            const ctx = document.getElementById('goldChart').getContext('2d');
            
            // Update price display
            document.getElementById('currentPrice').textContent = data.current_price.toFixed(2);
            document.getElementById('changeValue').textContent = 
                (data.change >= 0 ? '+' : '') + '$' + data.change.toFixed(2);
            document.getElementById('changePercent').textContent = 
                '(' + (data.change_percent >= 0 ? '+' : '') + data.change_percent.toFixed(2) + '%)';
            
            // Update change colors
            const changeElement = document.getElementById('changeValue');
            const percentElement = document.getElementById('changePercent');
            changeElement.className = 'change-value ' + (data.change >= 0 ? 'positive' : 'negative');
            percentElement.className = 'change-percent ' + (data.change_percent >= 0 ? 'positive' : 'negative');
            
            // Update stats
            const prices = data.prices;
            document.getElementById('highPrice').textContent = '$' + (data.high_24h || Math.max(...prices)).toFixed(2);
            document.getElementById('lowPrice').textContent = '$' + (data.low_24h || Math.min(...prices)).toFixed(2);
            document.getElementById('avgPrice').textContent = '$' + (prices.reduce((a, b) => a + b, 0) / prices.length).toFixed(2);
            document.getElementById('dataPoints').textContent = prices.length;
            document.getElementById('lastUpdated').textContent = data.timestamp;
            
            // Update API source
            const sourceElement = document.getElementById('apiSource');
            const sourceNameElement = document.getElementById('sourceName');
            sourceNameElement.textContent = data.source || 'Unknown';
            
            if (data.source && data.source.includes('Simulated')) {
                sourceElement.classList.add('fallback');
                document.getElementById('apiStatus').textContent = '⚠️ Using simulated data - all APIs unavailable';
            } else {
                sourceElement.classList.remove('fallback');
                document.getElementById('apiStatus').textContent = '✓ Live data connected';
            }
            
            // Create gradient
            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(255, 215, 0, 0.3)');
            gradient.addColorStop(1, 'rgba(255, 215, 0, 0)');
            
            if (goldChart) {
                goldChart.data.labels = data.labels;
                goldChart.data.datasets[0].data = data.prices;
                goldChart.data.datasets[0].borderColor = data.change >= 0 ? '#4caf50' : '#f44336';
                goldChart.data.datasets[0].backgroundColor = gradient;
                goldChart.update('none');
            } else {
                goldChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Gold Price (USD)',
                            data: data.prices,
                            borderColor: data.change >= 0 ? '#4caf50' : '#f44336',
                            backgroundColor: gradient,
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#ffd700',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: '#ffd700',
                                bodyColor: '#fff',
                                borderColor: '#ffd700',
                                borderWidth: 1,
                                displayColors: false,
                                callbacks: {
                                    label: function(context) {
                                        return '$' + context.parsed.y.toFixed(2);
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                },
                                ticks: {
                                    color: '#a0a0a0'
                                }
                            },
                            y: {
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                },
                                ticks: {
                                    color: '#a0a0a0',
                                    callback: function(value) {
                                        return '$' + value.toFixed(0);
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }
        
        // Initial load
        fetchGoldData();
        
        // Update every 5 seconds
        updateInterval = setInterval(fetchGoldData, 5000);
    </script>
</body>
</html>

