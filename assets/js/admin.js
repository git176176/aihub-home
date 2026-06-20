/* AIHub 后台交互 */
function aihubEdit(id) {
  var el = document.getElementById(id);
  if (!el) return;
  el.style.display = (el.style.display === 'none' || !el.style.display) ? 'block' : 'none';
}
