  </div>
  <script src="<?= Util::baseUrl('assets/js/app.js') ?>"></script>
  <!-- Global Image Modal -->
  <div class="modal-backdrop" id="imgModal">
    <div class="modal" style="width:min(900px,96vw)" >
      <div class="head"><strong id="im_title">Preview</strong><button class="x" data-modal-close="imgModal">✕</button></div>
      <div class="body">
        <img id="im_img" alt="Preview" style="max-width:100%; max-height:70vh; width:100%; height:auto; object-fit:contain; border-radius:10px; display:block; margin:0 auto 8px; background:#00000008;" />
        <div class="small muted" id="im_meta"></div>
      </div>
      <div class="foot"><button class="btn ghost" data-modal-close="imgModal">Close</button></div>
    </div>
  </div>
</body>
</html>
