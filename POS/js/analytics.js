document.addEventListener("DOMContentLoaded", function () {

    fetch("get_analytics.php")
        .then(res => res.json())
        .then(data => {

            console.log("Analytics loaded:", data);

           
            document.getElementById("weeklySales") &&
                (document.getElementById("weeklySales").textContent = data.weekly_sales);

            document.getElementById("weeklyOrders") &&
                (document.getElementById("weeklyOrders").textContent = data.weekly_orders);

            document.getElementById("cupsSold") &&
                (document.getElementById("cupsSold").textContent = data.cups);

            document.getElementById("bestCategory") &&
                (document.getElementById("bestCategory").textContent = data.best_category);

          
            const dailyChart = document.getElementById("dailyChart");

            if (dailyChart && window.Chart) {
                new Chart(dailyChart, {
                    type: "bar",
                    data: {
                        labels: data.daily_sales.map(d => d.date),
                        datasets: [{
                            label: "Daily Sales",
                            data: data.daily_sales.map(d => d.total),
                            backgroundColor: "#c08a4b"
                        }]
                    }
                });
            }

            
            const categoryBox = document.getElementById("categoryBox");

            if (categoryBox) {
                categoryBox.innerHTML = "";

                data.categories.forEach(cat => {
                    const div = document.createElement("div");
                    div.className = "analytics-row";
                    div.innerHTML = `
                        <span>${cat.category_name}</span>
                        <span>₱${cat.total_sales}</span>
                    `;
                    categoryBox.appendChild(div);
                });
            }

            
            const topItemsBox = document.getElementById("topItemsBox");

            if (topItemsBox) {
                topItemsBox.innerHTML = "";

                data.top_items.forEach(item => {
                    const div = document.createElement("div");
                    div.className = "analytics-row";
                    div.innerHTML = `
                        <span>${item.name}</span>
                        <span>${item.total_sold}</span>
                    `;
                    topItemsBox.appendChild(div);
                });
            }

           
            const tbody = document.getElementById("recent-tbody");

            if (tbody) {
                tbody.innerHTML = "";

                if (data.recent_orders && data.recent_orders.length > 0) {

                    data.recent_orders.forEach(order => {

                        const row = document.createElement("tr");

                        row.innerHTML = `
                            <td>#${order.id}</td>
                            <td>${order.items_count ?? 0}</td>
                            <td>${order.type ?? 'N/A'}</td>
                            <td>₱${order.total_amount}</td>
                            <td>${order.created_at}</td>
                        `;

                        tbody.appendChild(row);
                    });

                } else {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="5">No recent orders</td>
                        </tr>
                    `;
                }
            }

        })
        .catch(err => console.error("Analytics error:", err));

});