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
  <!-- Global Crop Modal (reused by asset/person uploaders) -->
  <div class="modal-backdrop" id="cropModal">
    <div class="modal" style="width:min(760px,96vw)">
      <div class="head"><strong>Crop Photo</strong><button class="x" data-modal-close="cropModal">✕</button></div>
      <div class="body">
        <div class="cropper">
          <div id="cropStage" class="crop-stage"></div>
          <div class="crop-controls">
            <label>Aspect
              <select id="cropAspect">
                <option value="free">Free</option>
                <option value="1">1:1</option>
                <option value="4/3">4:3</option>
                <option value="3/4">3:4</option>
                <option value="16/9">16:9</option>
                <option value="3/2">3:2</option>
              </select>
            </label>
            <label style="display:flex;align-items:center;gap:6px">Zoom
              <input id="cropZoom" type="range" min="1" max="3" step="0.01" value="1">
            </label>
            <div class="small muted">Drag to pan. Double‑click to reset.</div>
          </div>
        </div>
      </div>
      <div class="foot" style="justify-content: space-between;">
        <button class="btn ghost" type="button" data-modal-close="cropModal">Cancel</button>
        <div style="display:flex;gap:8px;align-items:center">
          <label class="small muted">Max size
            <select id="cropMax">
              <option value="1200">1200px</option>
              <option value="1600" selected>1600px</option>
              <option value="2000">2000px</option>
              <option value="3000">3000px</option>
            </select>
          </label>
          <button class="btn" type="button" id="cropApply">Apply</button>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
