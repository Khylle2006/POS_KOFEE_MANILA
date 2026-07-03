fetch('get_history.php')
.then(r => r.json())
.then(data => {

    let html = '';

    data.forEach(o => {
        html += `
        <tr>
            <td>#${o.id}</td>
            <td>${o.created_at}</td>
            <td>${o.items || '-'}</td>
            <td>${o.payment_method}</td>
            <td>₱${o.total_amount || '0.00'}</td>
            <td><button>View</button></td>
        </tr>`;
    });

    document.getElementById('history-tbody').innerHTML = html;
})
.catch(err => {
    console.error("History load error:", err);
});