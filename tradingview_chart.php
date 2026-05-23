<?php
// ================================================================================
// فایل جداگانه برای نمایش نمودار تریدینگ ویو
// ================================================================================

function display_tradingview_chart() {
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>TradingView Chart Integration</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 0;
                padding: 20px;
                background-color: #f5f6fa;
            }
            #tv_chart_container {
                width: 100%;
                height: 600px;
                border: 1px solid #d1d4dc;
                border-radius: 8px;
                overflow: hidden;
            }
            .info-box {
                background-color: #fff;
                border: 1px solid #d1d4dc;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 20px;
            }
        </style>
    </head>
    <body>
        <div class="info-box">
            <h2>TradingView Chart Integration</h2>
            <p>This page demonstrates the integration of TradingView\'s charting library.</p>
            <p>Note: To use the actual TradingView charting library, you need to:</p>
            <ol>
                <li>Obtain a license from <a href="https://www.tradingview.com/HTML5-stock-forex-bitcoin-charting-library/" target="_blank">TradingView</a></li>
                <li>Download the library files after licensing</li>
                <li>Replace the placeholder file with the actual library</li>
            </ol>
        </div>

        <div id="tv_chart_container"></div>

        <!-- Lightweight Charts Library (alternative to TradingView) -->
        <script type="text/javascript" src="./lightweight-charts.js"></script>
        
        <script type="text/javascript">
            // Initialize Lightweight Charts
            document.addEventListener(\'DOMContentLoaded\', function() {
                // Check if LightweightCharts library is loaded
                if (typeof LightweightCharts !== \'undefined\') {
                    // Initialize chart if library is present
                    const chartContainer = document.getElementById(\'tv_chart_container\');
                    const chart = LightweightCharts.createChart(chartContainer, {
                        width: chartContainer.clientWidth,
                        height: chartContainer.clientHeight,
                        layout: {
                            backgroundColor: '#ffffff',
                            textColor: '#000000',
                        },
                        grid: {
                            vertLines: {
                                color: '#f0f3fa',
                            },
                            horzLines: {
                                color: '#f0f3fa',
                            },
                        },
                        timeScale: {
                            timeVisible: true,
                            secondsVisible: false,
                        },
                    });

                    // Sample data for demonstration
                    const series = chart.addAreaSeries({
                        topColor: 'rgba(38, 198, 218, 0.56)',
                        bottomColor: 'rgba(38, 198, 218, 0.04)',
                        lineColor: 'rgba(38, 198, 218, 1)',
                        lineWidth: 2,
                    });

                    // Generate sample data
                    const now = Date.now();
                    const data = [];
                    for (let i = 0; i < 30; i++) {
                        data.push({
                            time: new Date(now - (30 - i) * 24 * 60 * 60 * 1000).toISOString().slice(0, 10),
                            value: Math.random() * 100 + 50,
                        });
                    }

                    series.setData(data);
                } else {
                    console.log("LightweightCharts library not loaded");
                    
                    // Show a message on the page about the missing library
                    const container = document.getElementById(\'tv_chart_container\');
                    container.innerHTML = `
                        <div style="
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            height: 100%;
                            background-color: #f8f9fc;
                            color: #888;
                            font-size: 18px;
                            text-align: center;
                            padding: 20px;
                        ">
                            <div>
                                <h3>Lightweight Charts Library Missing</h3>
                                <p>Please ensure lightweight-charts.js is properly loaded.</p>
                            </div>
                        </div>
                    `;
                }
            });
        </script>
    </body>
    </html>';
    
    echo $html;
}

// Call the function to display the chart
display_tradingview_chart();
?>