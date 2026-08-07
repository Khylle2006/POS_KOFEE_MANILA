// =============================
// Add Item
// =============================

const SELF = window.location.pathname;

// Open Add Modal
function openAdd() {
    document.getElementById("add-modal").classList.add("open");
}

// Close Add Modal
function closeAdd() {
    document.getElementById("add-modal").classList.remove("open");
}

// Save Item
async function addMenuItem() {

    const category = document.getElementById("add-category").value;
    const name = document.getElementById("add-name").value.trim();
    const desc = document.getElementById("add-desc").value.trim();
    const priceSmall = document.getElementById("add-price-small").value;
    const priceLarge = document.getElementById("add-price-large").value;

    if (!category) {
        return showMsg("error", "Please select a category.");
    }

    if (!name) {
        return showMsg("error", "Please enter the drink name.");
    }

    if (!priceSmall || parseFloat(priceSmall) <= 0) {
        return showMsg("error", "Enter a valid Regular Price.");
    }

    if (!priceLarge || parseFloat(priceLarge) <= 0) {
        return showMsg("error", "Enter a valid Up Size Price.");
    }

    const btn = document.querySelector(".submit-btn");

    btn.disabled = true;
    btn.innerHTML = "Saving...";

    const fd = new FormData();

    fd.append("action", "add");
    fd.append("category_id", category);
    fd.append("name", name);
    fd.append("description", desc);
    fd.append("price_small", priceSmall);
    fd.append("price_large", priceLarge);

    try {

        const response = await fetch(SELF, {
            method: "POST",
            body: fd
        });

        const data = await response.json();

        if (data.ok) {

            showMsg("success", "Item added successfully!");

            setTimeout(() => {
                location.reload();
            }, 1000);

        } else {

            showMsg("error", data.error || "Unable to add item.");

        }

    } catch (err) {

        console.error(err);

        showMsg("error", "Network Error.");

    }

    btn.disabled = false;
    btn.innerHTML = "➕ Add Item";
}

// Message Box
function showMsg(type, text) {

    const box = document.getElementById("add-msg");

    box.style.display = "block";

    if (type === "success") {
        box.style.background = "#e8f5e9";
        box.style.color = "#2e7d32";
        box.style.border = "1px solid #66bb6a";
    } else {
        box.style.background = "#ffebee";
        box.style.color = "#c62828";
        box.style.border = "1px solid #ef5350";
    }

    box.innerHTML = text;
}

// Reset Form
function resetForm() {

    document.getElementById("add-category").value = "";
    document.getElementById("add-name").value = "";
    document.getElementById("add-desc").value = "";
    document.getElementById("add-price-small").value = "";
    document.getElementById("add-price-large").value = "";

}