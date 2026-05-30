// Strongs search navigation — loaded by linking/strongs_home.html.twig
document.addEventListener('DOMContentLoaded', () => {
    const input  = document.getElementById('strongs-search')
    const select = document.getElementById('strongs-translation')
    const btn    = document.getElementById('strongs-btn')

    if (!input || !btn) return

    function navigate() {
        const v = input.value.trim().toUpperCase()
        const t = select ? select.value : 'SV'
        if (v) {
            window.location.href = '/link/strongs/' + encodeURIComponent(t) + '/' + encodeURIComponent(v)
        }
    }

    btn.addEventListener('click', navigate)
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') navigate() })
})
