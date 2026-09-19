        function renderAnalytics() {
        // Bar chart
        const days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        const vals = [1820, 2100, 1650, 2480, 2200, 2800, 1230];
        const max = Math.max(...vals);
        const barChart = document.getElementById('bar-chart');
        barChart.innerHTML = days.map((d, i) => `
            <div class="bar-col">
            <div class="bar-val">₱${vals[i]}</div>
            <div class="bar" style="height:${(vals[i]/max)*120}px"></div>
            <div class="bar-label">${d}</div>
            </div>
        `).join('');

        // MENU IMNIDA
        const cats = [
            { label:'Milk Tea', pct:42, color:'#8B5E3C' },
            { label:'Ice Coffee', pct:28, color:'#C9A96E' },
            { label:'Fruit Tea', pct:18, color:'#e07b5a' },
            { label:'Hot Coffee', pct:12, color:'#d4b896' },
        ];
        const r = 40, cx = 60, cy = 60;
        let offset = -Math.PI / 2;
        let paths = '';
        cats.forEach(c => {
            const angle = (c.pct / 100) * Math.PI * 2;
            const x1 = cx + r * Math.cos(offset);
            const y1 = cy + r * Math.sin(offset);
            offset += angle;
            const x2 = cx + r * Math.cos(offset);
            const y2 = cy + r * Math.sin(offset);
            const large = angle > Math.PI ? 1 : 0;
            paths += `<path d="M${cx},${cy} L${x1},${y1} A${r},${r} 0 ${large},1 ${x2},${y2} Z" fill="${c.color}" stroke="#fff" stroke-width="2"/>`;
        });
        document.getElementById('donut-svg').innerHTML = paths +
            `<circle cx="${cx}" cy="${cy}" r="24" fill="white"/>`+
            `<text x="${cx}" y="${cy+5}" text-anchor="middle" font-size="11" font-weight="800" fill="#2d2417">Sales</text>`;

        document.getElementById('donut-legend').innerHTML = cats.map(c =>
            `<div class="legend-item"><div class="legend-dot" style="background:${c.color}"></div>${c.label}<span class="legend-pct">${c.pct}%</span></div>`
        ).join('');

        // Top items
        const topItems = [
            { icon:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 3h12l-1.5 16a2 2 0 0 1-2 1.8H9.5A2 2 0 0 1 7.5 19L6 3z"/><line x1="10" y1="7" x2="14" y2="7"/><circle cx="10" cy="14" r="1.2" fill="currentColor"/><circle cx="14" cy="14" r="1.2" fill="currentColor"/><circle cx="12" cy="17" r="1.2" fill="currentColor"/></svg>', name:'Taro Milk Tea', count: 84 },
            { icon:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>', name:'Brown Sugar Boba', count: 67 },
            { icon:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 2h10l1 18a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L7 2z"/><line x1="5" y1="6" x2="19" y2="6"/><path d="M10 10h4"/><path d="M9 14h6"/><line x1="12" y1="12" x2="12" y2="4"/></svg>', name:'Iced Americano', count: 52 },
            { icon:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="14" r="7"/><path d="M12 7V3"/><path d="M12 3c3 0 5 1.5 5 4"/><circle cx="10" cy="13" r="1"/><circle cx="14" cy="13" r="1"/></svg>', name:'Mango Fruit Tea', count: 41 },
            { icon:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>', name:'Caramel Latte', count: 38 },
        ];
        document.getElementById('top-items-list').innerHTML = topItems.map((t, i) => `
            <div class="top-item-row">
            <div class="ti-rank">${i+1}</div>
            <div class="ti-icon">${t.icon}</div>
            <div class="ti-info">
                <div class="ti-name">${t.name}</div>
                <div class="ti-count">${t.count} cups sold</div>
            </div>
            <div class="ti-bar-wrap"><div class="ti-bar-fill" style="width:${(t.count/84)*100}%"></div></div>
            </div>
        `).join('');
        }

fetch('../api/get_analytics.php')
.then(r => r.json())
.then(data => {

    document.querySelectorAll('.stat-value')[0].innerHTML = '₱' + data.weekly_sales;
    document.querySelectorAll('.stat-value')[1].innerHTML = data.weekly_orders;
    document.querySelectorAll('.stat-value')[2].innerHTML = data.cups;
    document.querySelectorAll('.stat-value')[3].innerHTML = data.best_category;

    // REAL BAR CHART
    const barChart = document.getElementById('bar-chart');

    const days = data.daily_sales.map(d => d.date);
    const vals = data.daily_sales.map(d => d.total);

    const max = Math.max(...vals, 1);

    barChart.innerHTML = days.map((d, i) => `
        <div class="bar-col">
            <div class="bar-val">₱${vals[i]}</div>
            <div class="bar" style="height:${(vals[i]/max)*120}px"></div>
            <div class="bar-label">${d}</div>
        </div>
    `).join('');
});