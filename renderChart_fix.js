        function renderChart(c, lines) {
            const chartLib = document.getElementById('chartLib').value;
            const chartDiv = document.getElementById('chart');
            let prevVisibleRange = null;
            let prevPriceRange = null;

            // Ensure LightweightCharts is loaded
            if (typeof LightweightCharts === 'undefined') {
                console.error('LightweightCharts library is not loaded');
                chartDiv.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#fff;">Error: Chart library not loaded</div>';
                return;
            }

            // Wait for DOM to be ready
            if (!chartDiv.offsetWidth || !chartDiv.offsetHeight) {
                setTimeout(() => {
                    renderChart(c, lines);
                }, 100);
                return;
            }

            // Check for createChart method
            if (typeof LightweightCharts.createChart !== 'function') {
                console.error('LightweightCharts.createChart method is not available');
                chartDiv.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#fff;">Error: Chart library incomplete</div>';
                return;
            }

            // Clean up previous chart
            if (tvChart) {
                try {
                    if (tvChart.timeScale) {
                        prevVisibleRange = tvChart.timeScale().getVisibleRange();
                    }
                    tvChart.remove();
                } catch (e) {
                    console.warn('Could not save chart state:', e);
                }
            }

            chartDiv.innerHTML = '';
            chartDiv.style.backgroundColor = '#000000';

            try {
                // Get chart dimensions
                const rect = chartDiv.getBoundingClientRect();
                const chartWidth = Math.max(400, Math.floor(rect.width || 800));
                const chartHeight = Math.max(300, Math.floor(rect.height || 600));

                // Create chart
                chart = LightweightCharts.createChart(chartDiv, {
                    width: chartWidth,
                    height: chartHeight,
                    layout: {
                        background: { color: '#000000' },
                        textColor: '#ffffff',
                        fontSize: 12
                    },
                    grid: {
                        vertLines: { color: '#222831' },
                        horzLines: { color: '#222831' }
                    },
                    crosshair: {
                        mode: LightweightCharts.CrosshairMode.Normal
                    },
                    rightPriceScale: {
                        borderColor: '#2b3942',
                        visible: true
                    },
                    timeScale: {
                        borderColor: '#2b3942',
                        visible: true,
                        timeVisible: true,
                        secondsVisible: false
                    }
                });

                if (!chart) {
                    throw new Error('Chart object is null');
                }

                // Determine series creation method
                const useAddSeries = typeof chart.addSeries === 'function';
                const useAddLineSeries = typeof chart.addLineSeries === 'function';

                if (!useAddSeries && !useAddLineSeries) {
                    throw new Error('No series creation method available');
                }

                // Create main price series
                let lineSeries;
                if (useAddSeries) {
                    lineSeries = chart.addSeries({ type: 'Line' });
                } else {
                    lineSeries = chart.addLineSeries();
                }

                // Create SuperTrend series
                let stSeries;
                if (useAddSeries) {
                    stSeries = chart.addSeries({ type: 'Line' });
                } else {
                    stSeries = chart.addLineSeries();
                }

                // Prepare chart data with correct time format (Unix timestamps as numbers)
                if (c && c.x && c.y && c.x.length > 0 && c.y.length > 0) {
                    const priceData = c.x.map((x, i) => {
                        if (i < c.y.length) {
                            return {
                                time: Number(x), // Unix timestamp as number
                                value: Number(c.y[i])
                            };
                        }
                    }).filter(Boolean);

                    if (priceData.length > 0) {
                        lineSeries.setData(priceData);
                        lineSeries.applyOptions({
                            color: '#bdc3c7',
                            lineWidth: 2
                        });
                    }
                }

                // Prepare SuperTrend data
                if (c && c.st && c.dir && c.x && c.st.length > 0 && c.dir.length > 0) {
                    const stData = [];
                    let cd = c.dir[0];

                    c.x.forEach((x, i) => {
                        if (i >= c.st.length || i >= c.dir.length) return;
                        if (!c.st[i] || c.st[i] === 0) return;

                        stData.push({
                            time: Number(x),
                            value: Number(c.st[i])
                        });
                        cd = c.dir[i];
                    });

                    if (stData.length > 0) {
                        stSeries.setData(stData);
                        stSeries.applyOptions({
                            color: '#e74c3c',
                            lineWidth: 3
                        });
                    }
                }

                // Add markers
                const markers = [];

                // Helper to add markers
                const addMarkers = (indices, color, text, position, size) => {
                    if (!indices || !c.x) return;
                    indices.forEach(idx => {
                        if (idx < c.x.length) {
                            markers.push({
                                time: Number(c.x[idx]),
                                position: position,
                                color: color,
                                shape: size > 10 ? 'arrowUp' : 'circle',
                                text: text,
                                size: size
                            });
                        }
                    });
                };

                if (c.bx) addMarkers(c.bx, '#3498db', 'Buy', 'belowBar', 12);
                if (c.sx) addMarkers(c.sx, '#f39c12', 'B-Sell', 'aboveBar', 12);
                if (c.abx) addMarkers(c.abx, '#2ecc71', 'A-Buy', 'belowBar', 14);
                if (c.asx) addMarkers(c.asx, '#e74c3c', 'A-Sell', 'aboveBar', 14);

                if (markers.length > 0) {
                    lineSeries.setMarkers(markers);
                }

                // Restore view
                if (prevVisibleRange) {
                    try {
                        chart.timeScale().setVisibleRange(prevVisibleRange);
                    } catch (e) {
                        chart.timeScale().fitContent();
                    }
                } else {
                    chart.timeScale().fitContent();
                }

                tvChart = chart;

            } catch (error) {
                console.error('Error creating chart:', error);
                chartDiv.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#fff;">Error: ' + error.message + '</div>';
            }
        }

