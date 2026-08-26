const jsdom = require("jsdom");
const { JSDOM } = jsdom;

const dom = new JSDOM(`
    <table>
        <tbody>
            <tr>
                <td data-date="2025-01-10 10:00:00">Row 1 (Jan 10, 2025)</td>
            </tr>
            <tr>
                <td data-date="2026-02-20 10:00:00">Row 2 (Feb 20, 2026)</td>
            </tr>
        </tbody>
    </table>
`);

const document = dom.window.document;
const rows = Array.from(document.querySelectorAll("tbody tr"));

// Simulate QuickFilters.applyFilter('week')
const now = new Date("2026-02-22T09:29:42+07:00");
const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

const weekAgo = new Date(today);
weekAgo.setDate(weekAgo.getDate() - 7);

console.log("Filters:");
console.log("Today:", today.toISOString());
console.log("Week Ago:", weekAgo.toISOString());

rows.forEach(row => {
    let dateCell = row.querySelector('[data-date]') || row.cells[1] || row.cells[0];
    let dateValue = dateCell.getAttribute('data-date') || dateCell.textContent.trim();

    let rowDate = new Date(dateValue);
    const rowDateMidnight = new Date(rowDate.getFullYear(), rowDate.getMonth(), rowDate.getDate());

    let show = rowDateMidnight >= weekAgo;
    console.log("Row:", dateValue, "=> rowDateMidnight:", rowDateMidnight.toISOString(), "Show:", show);
});
