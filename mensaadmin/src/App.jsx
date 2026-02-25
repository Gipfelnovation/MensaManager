import React, { useState, useEffect, useRef } from 'react';
import { 
  Search, Home, Users, CreditCard, FileText, Activity, 
  AlertCircle, CheckCircle, RefreshCw, X, Edit2, Trash2, 
  DollarSign, ChevronRight, Ban, ShieldAlert, Check, Clock,
  Loader2, Lock, LogOut, Camera, RotateCcw, Settings, Save, Calendar, Plus
} from 'lucide-react';

const API_BASE = '/api'; 

// --- HILFSFUNKTIONEN ---
const getAboLabel = (type) => {
  if (type === 'full_year') return 'Ganzjahresabo';
  if (type === 'half_year') return 'Halbjahresabo';
  return type;
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  const d = new Date(dateString);
  if (isNaN(d.getTime())) return dateString;
  return d.toLocaleDateString('de-DE');
};

const formatWeekdays = (daysStr) => {
  if (!daysStr || typeof daysStr !== 'string') return '';
  const map = { '1': 'Mo', '2': 'Di', '3': 'Mi', '4': 'Do', '5': 'Fr' };
  return daysStr.split(',').map(d => map[d.trim()] || d.trim()).join(', ');
};

// --- DEPOSIT MODAL (EINZAHLUNG) ---
const DepositModal = ({ parentId, onClose, onComplete }) => {
  const [amount, setAmount] = useState('');
  const [description, setDescription] = useState('Manuelle Einzahlung (Bar)');

  const handleSubmit = (e) => {
    e.preventDefault();
    const val = parseFloat(amount.replace(',', '.'));
    if (!val || isNaN(val) || val <= 0) {
      alert("Bitte einen gültigen Betrag eingeben.");
      return;
    }
    onComplete(parentId, val, description);
  };

  return (
    <div className="fixed inset-0 z-[100] bg-slate-900/60 backdrop-blur-sm flex justify-center items-center p-4">
      <div className="bg-white w-full max-w-sm rounded-2xl shadow-xl overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div className="p-4 border-b border-gray-100 flex justify-between items-center">
          <h3 className="font-bold text-gray-900">Guthaben aufladen</h3>
          <button onClick={onClose} className="p-1 hover:bg-gray-100 rounded-full text-gray-400">
            <X className="h-5 w-5" />
          </button>
        </div>
        <form onSubmit={handleSubmit} className="p-5 space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Betrag (€)</label>
            <input 
              type="number" step="0.01" required autoFocus
              placeholder="z.B. 20.00"
              className="w-full px-3 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none"
              value={amount} onChange={e => setAmount(e.target.value)} 
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Beschreibung / Grund</label>
            <input 
              type="text" required 
              className="w-full px-3 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-emerald-500 outline-none"
              value={description} onChange={e => setDescription(e.target.value)} 
            />
          </div>
          <div className="pt-2 flex gap-3">
            <button type="button" onClick={onClose} className="flex-1 px-4 py-2 border border-gray-300 rounded-xl text-gray-700 font-medium hover:bg-gray-50">Abbrechen</button>
            <button type="submit" className="flex-1 px-4 py-2 bg-emerald-600 text-white rounded-xl font-medium hover:bg-emerald-700 flex items-center justify-center gap-2">
              <Plus className="h-4 w-4"/> Einzahlen
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

// --- MARK ABO PAID MODAL ---
const MarkAboPaidModal = ({ aboId, onClose, onComplete }) => {
  const [method, setMethod] = useState('Überweisung');
  const [date, setDate] = useState(new Date().toISOString().split('T')[0]);

  const handleSubmit = (e) => {
    e.preventDefault();
    const txNr = `${method} - ${date}`;
    onComplete(aboId, txNr);
  };

  return (
    <div className="fixed inset-0 z-[100] bg-slate-900/60 backdrop-blur-sm flex justify-center items-center p-4">
      <div className="bg-white w-full max-w-sm rounded-2xl shadow-xl overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div className="p-4 border-b border-gray-100 flex justify-between items-center">
          <h3 className="font-bold text-gray-900">Zahlung bestätigen</h3>
          <button onClick={onClose} className="p-1 hover:bg-gray-100 rounded-full text-gray-400">
            <X className="h-5 w-5" />
          </button>
        </div>
        <form onSubmit={handleSubmit} className="p-5 space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Zahlungsmethode</label>
            <input 
              type="text" required 
              placeholder="z.B. Barzahlung, Überweisung"
              className="w-full px-3 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none"
              value={method} onChange={e => setMethod(e.target.value)} 
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Eingangsdatum</label>
            <input 
              type="date" required 
              className="w-full px-3 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none"
              value={date} onChange={e => setDate(e.target.value)} 
            />
          </div>
          <div className="pt-2 flex gap-3">
            <button type="button" onClick={onClose} className="flex-1 px-4 py-2 border border-gray-300 rounded-xl text-gray-700 font-medium hover:bg-gray-50">Abbrechen</button>
            <button type="submit" className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-xl font-medium hover:bg-blue-700">Speichern</button>
          </div>
        </form>
      </div>
    </div>
  );
};

// --- ASSIGN CARD MODAL (CAMERA & SCANNER) ---
const AssignCardModal = ({ assignData, onClose, onComplete }) => {
  const [step, setStep] = useState('face'); // 'face', 'face-review', 'barcode', 'confirm'
  const [faceImg, setFaceImg] = useState(null);
  const [barcode, setBarcode] = useState('');
  const [manualInput, setManualInput] = useState('');
  const [cameraError, setCameraError] = useState('');

  const videoRef = useRef(null);
  const canvasRef = useRef(null);
  const streamRef = useRef(null);

  const stopCamera = () => {
    if (streamRef.current) {
      streamRef.current.getTracks().forEach(t => t.stop());
      streamRef.current = null;
    }
  };

  const startCamera = async (mode) => {
    stopCamera();
    setCameraError('');
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: mode, width: { ideal: 1280 }, height: { ideal: 720 } }
      });
      streamRef.current = stream;
      if (videoRef.current) {
        videoRef.current.srcObject = stream;
        if (mode === 'user') {
          videoRef.current.style.transform = 'scaleX(-1)';
        } else {
          videoRef.current.style.transform = 'none';
        }
        await videoRef.current.play(); 
      }
    } catch (err) {
      setCameraError('Kamerazugriff verweigert oder Gerät hat keine Kamera.');
    }
  };

  useEffect(() => {
    if (step === 'face') startCamera('user');
    if (step === 'barcode') startCamera('environment');
    return () => stopCamera();
  }, [step]);

  // Barcode Scanner Loop
  useEffect(() => {
    if (step !== 'barcode') return;
    let rafId;
    let detector = null;
    let stopped = false;

    const initDetector = async () => {
      if ('BarcodeDetector' in window) {
        try {
          const formats = await window.BarcodeDetector.getSupportedFormats();
          detector = new window.BarcodeDetector({ formats: formats.length ? formats : ['qr_code', 'code_128', 'ean_13', 'ean_8', 'upc_e', 'upc_a'] });
        } catch (e) {
          detector = new window.BarcodeDetector();
        }
      } else {
        setCameraError('Barcode-Scanner wird von diesem Browser nicht unterstützt. Bitte Kartennummer manuell eingeben.');
      }
    };

    const detectLoop = async () => {
      if (stopped || step !== 'barcode') return;
      if (videoRef.current && videoRef.current.readyState >= 2 && detector && canvasRef.current) {
        const video = videoRef.current;
        const canvas = canvasRef.current;
        const ctx = canvas.getContext('2d');
        const w = Math.min(video.videoWidth, 640);
        const h = Math.min(video.videoHeight, 480);
        if (canvas.width !== w) canvas.width = w; 
        if (canvas.height !== h) canvas.height = h;
        
        try {
          ctx.drawImage(video, 0, 0, w, h);
          const barcodes = await detector.detect(canvas);
          if (barcodes && barcodes.length > 0 && barcodes[0].rawValue) {
            setBarcode(barcodes[0].rawValue);
            setStep('confirm');
            return;
          }
        } catch (e) {}
      }
      setTimeout(() => {
        if (!stopped) rafId = requestAnimationFrame(detectLoop);
      }, 250); 
    };
    
    initDetector().then(() => { detectLoop(); });
    return () => { stopped = true; if(rafId) cancelAnimationFrame(rafId); };
  }, [step]);

  const captureFace = () => {
    const video = videoRef.current;
    if (!video || video.readyState < 2) return;
    
    const canvas = document.createElement('canvas');
    canvas.width = 827; 
    canvas.height = 1063;
    const ctx = canvas.getContext('2d');
    
    const vw = video.videoWidth;
    const vh = video.videoHeight;
    const srcAspect = vw / vh;
    const targetAspect = 827 / 1063;
    
    let sx = 0, sy = 0, sw = vw, sh = vh;
    if (srcAspect > targetAspect) {
      sw = Math.round(vh * targetAspect);
      sx = Math.round((vw - sw) / 2);
    } else {
      sh = Math.round(vw / targetAspect);
      sy = Math.round((vh - sh) / 2);
    }

    const isUser = streamRef.current?.getVideoTracks()[0]?.getSettings()?.facingMode === 'user' || video.style.transform.includes('scaleX(-1)');
    if (isUser) {
      ctx.translate(canvas.width, 0);
      ctx.scale(-1, 1);
    }
    
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
    setFaceImg(canvas.toDataURL('image/png'));
    setStep('face-review');
  };

  const handleSubmit = () => {
    onComplete(assignData.cardId, barcode, faceImg);
  };

  return (
    <div className="fixed inset-0 z-[100] bg-slate-900/90 backdrop-blur-sm flex justify-center items-center p-4">
      <div className="bg-white w-full max-w-xl rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        <div className="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
          <div>
            <h3 className="font-bold text-gray-900">Karte zuweisen</h3>
            <p className="text-xs text-gray-500">Für Schüler: <span className="font-semibold text-gray-700">{assignData.studentName}</span></p>
          </div>
          <button onClick={onClose} className="p-2 bg-white rounded-full text-gray-400 hover:text-gray-600 shadow-sm border border-gray-100">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto p-6 flex flex-col items-center">
          {step === 'face' && (
            <div className="w-full flex flex-col items-center space-y-4">
              <div className="text-center">
                <p className="font-medium text-gray-800">Schritt 1: Schüler-Foto aufnehmen</p>
                <p className="text-sm text-gray-500">Bitte das Gesicht im Rahmen positionieren.</p>
              </div>
              
              <div className="relative w-full max-w-sm aspect-[3/4] bg-black rounded-xl overflow-hidden flex items-center justify-center">
                {cameraError ? <p className="text-white text-sm px-4 text-center">{cameraError}</p> : (
                  <>
                    <video ref={videoRef} autoPlay playsInline muted className="w-full h-full object-cover"></video>
                    <div className="absolute inset-0 bg-black/50 pointer-events-none" style={{ WebkitMaskImage: 'radial-gradient(ellipse 45% 35% at 50% 40%, rgba(0,0,0,1) 98%, rgba(0,0,0,0) 100%)', maskImage: 'radial-gradient(ellipse 45% 35% at 50% 40%, rgba(0,0,0,1) 98%, rgba(0,0,0,0) 100%)' }}></div>
                    <div className="absolute inset-x-8 top-12 bottom-16 border-2 border-dashed border-white/50 rounded-full pointer-events-none"></div>
                  </>
                )}
              </div>

              <div className="flex gap-4 w-full max-w-sm">
                <button onClick={() => startCamera(videoRef.current?.style.transform.includes('-1') ? 'environment' : 'user')} className="p-3 bg-gray-100 rounded-xl text-gray-600 hover:bg-gray-200" title="Kamera drehen">
                  <RotateCcw className="h-6 w-6" />
                </button>
                <button onClick={captureFace} disabled={!!cameraError} className="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-xl flex items-center justify-center gap-2">
                  <Camera className="h-5 w-5" /> Foto aufnehmen
                </button>
              </div>
            </div>
          )}

          {step === 'face-review' && (
            <div className="w-full flex flex-col items-center space-y-4">
              <div className="text-center">
                <p className="font-medium text-gray-800">Schritt 1.5: Foto überprüfen</p>
                <p className="text-sm text-gray-500">Ist das Gesicht gut erkennbar?</p>
              </div>
              
              <div className="w-full max-w-sm aspect-[3/4] bg-gray-100 rounded-xl overflow-hidden border border-gray-200 shadow-inner">
                <img src={faceImg} alt="Vorschau" className="w-full h-full object-cover" />
              </div>

              <div className="flex gap-4 w-full max-w-sm">
                <button onClick={() => setStep('face')} className="flex-1 p-3 bg-gray-100 text-gray-700 hover:bg-gray-200 font-semibold rounded-xl transition-colors">
                  Neu aufnehmen
                </button>
                <button onClick={() => setStep('barcode')} className="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-xl transition-colors flex items-center justify-center gap-2">
                  <Check className="h-5 w-5" /> Verwenden
                </button>
              </div>
            </div>
          )}

          {step === 'barcode' && (
            <div className="w-full flex flex-col items-center space-y-4">
               <div className="text-center">
                <p className="font-medium text-gray-800">Schritt 2: Barcode der Karte scannen</p>
                <p className="text-sm text-gray-500">Halte den Barcode der Mensakarte in die Kamera.</p>
              </div>

              <div className="relative w-full max-w-sm aspect-video bg-black rounded-xl overflow-hidden flex items-center justify-center">
                 {cameraError ? <p className="text-white text-sm px-4 text-center">{cameraError}</p> : (
                  <>
                    <video ref={videoRef} autoPlay playsInline muted className="w-full h-full object-cover"></video>
                    <div className="absolute w-[60%] h-[40%] border-2 border-blue-500 rounded-lg shadow-[0_0_0_9999px_rgba(0,0,0,0.5)] pointer-events-none"></div>
                    <div className="absolute bottom-4 bg-black/60 text-white px-3 py-1 rounded-full text-xs animate-pulse">Suche Barcode...</div>
                    <canvas ref={canvasRef} className="hidden"></canvas>
                  </>
                 )}
              </div>

              <div className="w-full max-w-sm pt-4 border-t border-gray-100">
                <p className="text-xs text-gray-500 mb-2 text-center">Alternativ: Barcode manuell eingeben</p>
                <div className="flex gap-2">
                  <input type="text" value={manualInput} onChange={e => setManualInput(e.target.value)} placeholder="Kartennummer..." className="flex-1 border border-gray-300 rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" />
                  <button onClick={() => { if(manualInput) { setBarcode(manualInput); setStep('confirm'); } }} className="bg-gray-900 text-white px-4 py-2 rounded-lg font-medium">OK</button>
                </div>
              </div>
              
              <button onClick={() => setStep('face-review')} className="text-sm text-gray-500 hover:text-gray-800 mt-2">Zurück zum Foto</button>
            </div>
          )}

          {step === 'confirm' && (
            <div className="w-full flex flex-col items-center space-y-6">
              <div className="text-center">
                <CheckCircle className="h-12 w-12 text-emerald-500 mx-auto mb-2" />
                <h3 className="text-xl font-bold text-gray-900">Daten erfasst</h3>
                <p className="text-sm text-gray-500">Bitte überprüfe die Eingaben.</p>
              </div>

              <div className="w-full bg-gray-50 rounded-xl p-4 border border-gray-200 flex gap-4">
                <div className="w-24 h-32 bg-gray-200 rounded-lg overflow-hidden shrink-0 border border-gray-300">
                  <img src={faceImg} alt="Schüler" className="w-full h-full object-cover" />
                </div>
                <div className="flex flex-col justify-center space-y-2">
                  <div>
                    <p className="text-xs text-gray-500 font-medium">Schüler</p>
                    <p className="font-bold text-gray-900">{assignData.studentName}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500 font-medium">Kartennummer</p>
                    <p className="font-mono text-sm bg-white border border-gray-200 px-2 py-1 rounded inline-block mt-0.5">{barcode}</p>
                  </div>
                </div>
              </div>

              <div className="flex gap-3 w-full">
                <button onClick={() => setStep('barcode')} className="flex-1 py-3 px-4 border border-gray-300 rounded-xl text-gray-700 font-medium hover:bg-gray-50">Neu scannen</button>
                <button onClick={handleSubmit} className="flex-1 py-3 px-4 bg-blue-600 text-white rounded-xl font-bold hover:bg-blue-700 shadow-sm flex items-center justify-center gap-2">
                  <Check className="h-5 w-5"/> Zuweisen
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default function App() {
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [currentView, setCurrentView] = useState({ type: 'dashboard', id: null });
  const [isSearchOpen, setIsSearchOpen] = useState(false);
  
  const [assignModalData, setAssignModalData] = useState(null);
  const [payAboModalData, setPayAboModalData] = useState(null);
  const [depositModalData, setDepositModalData] = useState(null); // NEU

  const [dashboardData, setDashboardData] = useState(null);
  const [parentData, setParentData] = useState(null);
  const [pendingCards, setPendingCards] = useState([]);
  const [unpaidAbos, setUnpaidAbos] = useState([]);

  useEffect(() => {
    fetchDashboard();
  }, []);

  const fetchJson = async (url) => {
    const res = await fetch(url, { credentials: 'include' });
    if (res.status === 401 || res.status === 403) {
      setIsAuthenticated(false);
      setIsLoading(false);
      throw new Error("Nicht authorisiert");
    }
    const textData = await res.text();
    if (!res.ok) throw new Error(`HTTP Fehler ${res.status}: ${textData.substring(0, 100)}`);
    
    try {
      const data = JSON.parse(textData);
      if (data.error) throw new Error(data.error);
      return data;
    } catch (parseError) {
      throw new Error("Server hat ungültige Daten zurückgegeben.");
    }
  };

  const fetchDashboard = async () => {
    setIsLoading(true);
    setError(null);
    try {
      const data = await fetchJson(`${API_BASE}/data.php?action=dashboard`);
      setDashboardData(data);
      setIsAuthenticated(true);
      setCurrentView({ type: 'dashboard', id: null });
    } catch (err) {
      if(err.message !== "Nicht authorisiert") setError(err.message);
    } finally {
      setIsLoading(false);
    }
  };

  const loadParentDetail = async (parentId) => {
    setIsSearchOpen(false);
    setIsLoading(true); 
    try {
      const data = await fetchJson(`${API_BASE}/data.php?action=parent&id=${parentId}`);
      setParentData(data);
      setCurrentView({ type: 'parentDetail', id: parentId });
    } catch (err) {
      alert("Fehler beim Laden des Accounts: " + err.message);
    } finally {
      setIsLoading(false);
    }
  };

  const loadPendingCards = async () => {
    setIsLoading(true);
    try {
      const data = await fetchJson(`${API_BASE}/data.php?action=pending`);
      setPendingCards(data.pendingCards || []);
      setCurrentView({ type: 'pendingCards', id: null });
    } catch (err) {
      alert("Fehler: " + err.message);
    } finally {
      setIsLoading(false);
    }
  };

  const loadUnpaidAbos = async () => {
    setIsLoading(true);
    try {
      const data = await fetchJson(`${API_BASE}/data.php?action=unpaid`);
      setUnpaidAbos(data.unpaidAbos || []);
      setCurrentView({ type: 'unpaidAbos', id: null });
    } catch (err) {
      alert("Fehler: " + err.message);
    } finally {
      setIsLoading(false);
    }
  };

  const refreshCurrentView = () => {
    if (currentView.type === 'dashboard') fetchDashboard();
    else if (currentView.type === 'parentDetail') loadParentDetail(currentView.id);
    else if (currentView.type === 'pendingCards') loadPendingCards();
    else if (currentView.type === 'unpaidAbos') loadUnpaidAbos();
  };

  const runAction = async (actionName, data, onSuccess) => {
    try {
      const res = await fetch(`${API_BASE}/actions.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: actionName, data }),
        credentials: 'include'
      });
      const textData = await res.text();
      let result = JSON.parse(textData);
      
      if (result.success) {
        onSuccess();
      } else {
        alert('Fehler: ' + (result.error || 'Aktion fehlgeschlagen'));
      }
    } catch (err) {
      alert('Verbindungsfehler bei Ausführung der Aktion.');
    }
  };

  const assignCardNumber = (cardId, studentName) => {
    setAssignModalData({ cardId, studentName });
  };

  const submitAssignedCard = (cardId, cardNumber, faceData) => {
    runAction('assignCardNumber', { cardId, cardNumber, faceData }, () => {
      setAssignModalData(null);
      refreshCurrentView();
    });
  };

  const updateCardStatus = (cardId, newStatus) => {
    runAction('updateCardStatus', { cardId, newStatus }, () => refreshCurrentView());
  };

  const refundTransaction = (txId) => {
    runAction('refundTransaction', { txId }, () => refreshCurrentView());
  };

  const markAboPaid = (aboId, txNr) => {
    runAction('markAboPaid', { aboId, transactionNr: txNr }, () => {
      setPayAboModalData(null);
      refreshCurrentView();
    });
  };

  const submitDeposit = (parentId, amount, description) => {
    runAction('deposit', { parentId, amount, description }, () => {
      setDepositModalData(null);
      refreshCurrentView();
    });
  };

  const deleteAbo = (aboId) => {
    if(window.confirm("Abonnement wirklich löschen?")) {
      runAction('deleteAbo', { aboId }, () => refreshCurrentView());
    }
  };

  const editStudent = (studentId) => {
    const newName = window.prompt("Neuer Name für Schüler:");
    if (newName) {
      runAction('editStudent', { studentId, name: newName }, () => refreshCurrentView());
    }
  };

  // --- COMPONENTS ---
  const LoginScreen = () => {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [stayLoggedIn, setStayLoggedIn] = useState(false);
    const [loginErr, setLoginErr] = useState('');
    const [isLoggingIn, setIsLoggingIn] = useState(false);

    const handleLogin = async (e) => {
      e.preventDefault();
      setIsLoggingIn(true);
      setLoginErr('');
      try {
        const res = await fetch(`${API_BASE}/login.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email, passwort: password, angemeldet_bleiben: stayLoggedIn }),
          credentials: 'include'
        });
        const textData = await res.text();
        const data = JSON.parse(textData);
        
        if (data.success && data.isAdmin) {
          fetchDashboard(); 
        } else {
          setLoginErr(data.error || 'Login fehlgeschlagen. Keine Admin-Rechte?');
        }
      } catch (err) {
        setLoginErr(`Verbindungsfehler: ${err.message}`);
      } finally {
        setIsLoggingIn(false);
      }
    };

    return (
      <div className="min-h-screen bg-slate-50 flex items-center justify-center p-4">
        <div className="max-w-md w-full bg-white rounded-2xl shadow-xl border border-gray-100 p-8">
          <div className="text-center mb-8">
            <div className="bg-blue-600 p-3 rounded-xl inline-block mb-4">
              <Lock className="h-8 w-8 text-white" />
            </div>
            <h2 className="text-2xl font-bold text-gray-900">Mensa Admin Login</h2>
          </div>
          {loginErr && (
            <div className="mb-6 p-4 bg-red-50 rounded-xl border border-red-100 flex items-start gap-3">
              <AlertCircle className="h-5 w-5 text-red-600 mt-0.5" />
              <p className="text-sm text-red-700">{loginErr}</p>
            </div>
          )}
          <form onSubmit={handleLogin} className="space-y-5">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">E-Mail Adresse</label>
              <input type="email" required className="w-full px-4 py-2.5 rounded-xl border border-gray-300 focus:ring-2 focus:ring-blue-500" value={email} onChange={e => setEmail(e.target.value)} />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Passwort</label>
              <input type="password" required className="w-full px-4 py-2.5 rounded-xl border border-gray-300 focus:ring-2 focus:ring-blue-500" value={password} onChange={e => setPassword(e.target.value)} />
            </div>
            <div className="flex items-center gap-2">
              <input type="checkbox" id="stayLoggedIn" checked={stayLoggedIn} onChange={e => setStayLoggedIn(e.target.checked)} className="w-4 h-4 text-blue-600 rounded" />
              <label htmlFor="stayLoggedIn" className="text-sm text-gray-700 cursor-pointer">Angemeldet bleiben</label>
            </div>
            <button type="submit" disabled={isLoggingIn} className="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-xl flex items-center justify-center gap-2 disabled:opacity-70">
              {isLoggingIn ? <Loader2 className="h-5 w-5 animate-spin" /> : 'Anmelden'}
            </button>
          </form>
        </div>
      </div>
    );
  };

  const Navigation = () => (
    <nav className="bg-white border-b border-gray-200 sticky top-0 z-40">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex justify-between h-16">
          <div className="flex items-center cursor-pointer" onClick={() => fetchDashboard()}>
            <div className="flex-shrink-0 flex items-center gap-2">
              <div className="bg-blue-600 p-2 rounded-lg">
                <CreditCard className="h-6 w-6 text-white" />
              </div>
              <span className="font-bold text-xl text-gray-900 hidden sm:block">MensaAdmin</span>
            </div>
          </div>
          <div className="flex items-center flex-1 justify-center px-4 sm:px-8 lg:px-16">
            <button onClick={() => setIsSearchOpen(true)} className="w-full max-w-2xl flex items-center gap-3 px-4 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-500 rounded-full transition-colors border border-transparent hover:border-gray-300">
              <Search className="h-5 w-5" />
              <span className="text-sm font-medium">Suchen nach Eltern, Schülern, Karten...</span>
            </button>
          </div>
          <div className="flex items-center">
            <button onClick={() => { setIsAuthenticated(false); setDashboardData(null); }} className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg ml-4">
              <LogOut className="h-5 w-5" />
            </button>
          </div>
        </div>
      </div>
    </nav>
  );

  const SearchOverlay = () => {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [isSearching, setIsSearching] = useState(false);
    
    useEffect(() => {
      if (query.trim().length < 2) {
        setResults([]);
        return;
      }
      const delayDebounceFn = setTimeout(async () => {
        setIsSearching(true);
        try {
          const data = await fetchJson(`${API_BASE}/data.php?action=search&q=${encodeURIComponent(query)}`);
          setResults(data.results || []);
        } catch (e) {
        } finally {
          setIsSearching(false);
        }
      }, 300); 

      return () => clearTimeout(delayDebounceFn);
    }, [query]);

    if (!isSearchOpen) return null;

    const getIcon = (iconName) => {
      switch(iconName) {
        case 'Users': return Users;
        case 'Edit2': return Edit2;
        case 'CreditCard': return CreditCard;
        case 'FileText': return FileText;
        default: return Users;
      }
    };

    return (
      <div className="fixed inset-0 z-50 bg-gray-900/50 backdrop-blur-sm flex justify-center items-start pt-20 px-4">
        <div className="bg-white w-full max-w-3xl rounded-2xl shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-200">
          <div className="relative flex items-center p-4 border-b border-gray-100">
            {isSearching ? <Loader2 className="h-6 w-6 text-blue-500 absolute left-6 animate-spin" /> : <Search className="h-6 w-6 text-blue-500 absolute left-6" />}
            <input 
              autoFocus type="text" placeholder="Wonach suchst du?" 
              className="w-full pl-12 pr-12 py-3 text-lg bg-transparent border-none focus:ring-0 outline-none text-gray-900 placeholder-gray-400"
              value={query} onChange={(e) => setQuery(e.target.value)}
            />
            <button onClick={() => setIsSearchOpen(false)} className="absolute right-6 p-1 hover:bg-gray-100 rounded-full text-gray-500">
              <X className="h-5 w-5" />
            </button>
          </div>
          
          <div className="max-h-[60vh] overflow-y-auto p-2">
            {query.length > 0 && query.length < 2 && <p className="text-center text-gray-500 py-8">Bitte mindestens 2 Zeichen eingeben...</p>}
            {query.length >= 2 && results.length === 0 && !isSearching && <p className="text-center text-gray-500 py-8">Keine Ergebnisse für "{query}" gefunden.</p>}
            
            {results.map((item, idx) => {
              const Icon = getIcon(item.iconType);
              return (
                <button key={idx} onClick={() => loadParentDetail(item.parentId)} className="w-full flex items-center justify-between p-4 hover:bg-blue-50/80 rounded-xl transition-colors text-left group">
                  <div className="flex items-center gap-4">
                    <div className="p-2 bg-gray-100 text-gray-500 rounded-lg group-hover:bg-blue-100 group-hover:text-blue-600"><Icon className="h-5 w-5" /></div>
                    <div><h4 className="font-semibold text-gray-900">{item.title}</h4><p className="text-sm text-gray-500">{item.subtitle}</p></div>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="text-xs font-medium px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 border border-gray-200">{item.category}</span>
                    <ChevronRight className="h-4 w-4 text-gray-400 group-hover:text-blue-500" />
                  </div>
                </button>
              )
            })}
          </div>
        </div>
      </div>
    );
  };

  const Dashboard = () => {
    if (!dashboardData) return null;
    const { stats, recentTransactions, defaultValues } = dashboardData;
    const [prices, setPrices] = useState(defaultValues || {});
    const [isSaving, setIsSaving] = useState(false);

    const handlePriceChange = (e) => {
      setPrices({ ...prices, [e.target.name]: e.target.value });
    };

    const savePrices = () => {
      setIsSaving(true);
      // Aktion 'updateSettings' wird an actions.php gesendet
      runAction('updateSettings', prices, () => {
        setIsSaving(false);
        refreshCurrentView();
      });
    };

    const cards = [
      { label: 'Gesamtguthaben im System', value: `${stats.totalBalance.toFixed(2)} €`, icon: DollarSign, color: 'text-emerald-600', bg: 'bg-emerald-100' },
      { label: 'Aktive Chipkarten', value: stats.activeCards, icon: CreditCard, color: 'text-blue-600', bg: 'bg-blue-100' },
      { 
        label: 'Unbezahlte Abos', value: stats.unpaidAbos, icon: AlertCircle, color: 'text-amber-600', bg: 'bg-amber-100',
        onClick: () => loadUnpaidAbos()
      },
      { 
        label: 'Ausstehende Karten', value: stats.pendingCards, icon: Clock, color: 'text-purple-600', bg: 'bg-purple-100',
        onClick: () => loadPendingCards()
      },
    ];

    return (
      <div className="p-6 max-w-7xl mx-auto space-y-6">
        <h2 className="text-2xl font-bold text-gray-800">System Dashboard</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {cards.map((stat, idx) => (
            <div key={idx} onClick={stat.onClick} className={`bg-white rounded-2xl p-6 shadow-sm border border-gray-100 flex items-center gap-4 hover:shadow-md transition-shadow ${stat.onClick ? 'cursor-pointer hover:border-purple-200 hover:bg-purple-50/30' : ''}`}>
              <div className={`p-4 rounded-xl ${stat.bg}`}><stat.icon className={`h-6 w-6 ${stat.color}`} /></div>
              <div>
                <p className="text-sm font-medium text-gray-500">{stat.label}</p>
                <h3 className="text-2xl font-bold text-gray-900">{stat.value}</h3>
              </div>
            </div>
          ))}
        </div>
        
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-8">
          <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h3 className="text-lg font-semibold text-gray-800 mb-4">Letzte Systemaktivitäten</h3>
            <div className="space-y-4">
              {recentTransactions.map((tx, idx) => (
                <div key={idx} className="flex items-center justify-between p-4 rounded-xl bg-gray-50 border border-gray-100">
                  <div className="flex items-center gap-4">
                    <div className={`p-2 rounded-full ${tx.amount < 0 ? 'bg-red-100 text-red-600' : 'bg-emerald-100 text-emerald-600'}`}>
                      <Activity className="h-4 w-4" />
                    </div>
                    <div>
                      <p className="font-medium text-gray-900">{tx.description}</p>
                      <p className="text-xs text-gray-500">{tx.date}</p>
                    </div>
                  </div>
                  <div className={`font-bold ${tx.amount < 0 ? 'text-gray-900' : 'text-emerald-600'}`}>
                    {tx.amount > 0 ? '+' : ''}{tx.amount.toFixed(2)} €
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h3 className="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
              <Settings className="h-5 w-5 text-gray-500" /> Preise & Einstellungen
            </h3>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Preis Ganzjahresabo (pro ausgewähltem Tag)</label>
                <div className="relative">
                  <input type="number" step="0.01" name="full_year_per_day" value={prices.full_year_per_day || ''} onChange={handlePriceChange} className="w-full pl-3 pr-10 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none" />
                  <span className="absolute right-3 top-2.5 text-gray-400">€</span>
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Preis Halbjahresabo (pro ausgewähltem Tag)</label>
                <div className="relative">
                  <input type="number" step="0.01" name="half_year_per_day" value={prices.half_year_per_day || ''} onChange={handlePriceChange} className="w-full pl-3 pr-10 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none" />
                  <span className="absolute right-3 top-2.5 text-gray-400">€</span>
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Preis Eintritt ohne Abo</label>
                <div className="relative">
                  <input type="number" step="0.01" name="single_entry" value={prices.single_entry || ''} onChange={handlePriceChange} className="w-full pl-3 pr-10 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none" />
                  <span className="absolute right-3 top-2.5 text-gray-400">€</span>
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Preis Nachschlag</label>
                <div className="relative">
                  <input type="number" step="0.01" name="single_entry_reuse" value={prices.single_entry_reuse || ''} onChange={handlePriceChange} className="w-full pl-3 pr-10 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none" />
                  <span className="absolute right-3 top-2.5 text-gray-400">€</span>
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Preis Kartenpfand</label>
                <div className="relative">
                  <input type="number" step="0.01" name="card_deposit" value={prices.card_deposit || ''} onChange={handlePriceChange} className="w-full pl-3 pr-10 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none" />
                  <span className="absolute right-3 top-2.5 text-gray-400">€</span>
                </div>
              </div>
              <button onClick={savePrices} disabled={isSaving} className="w-full mt-4 bg-gray-900 hover:bg-gray-800 text-white font-medium py-2.5 px-4 rounded-xl transition-colors flex items-center justify-center gap-2">
                {isSaving ? <Loader2 className="h-5 w-5 animate-spin" /> : <Save className="h-5 w-5" />} Speichern
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  };

  const ParentDetail = () => {
    if (!parentData || !parentData.parent) return <div className="p-8 text-center">Elternaccount Daten nicht verfügbar.</div>;
    const { parent, students, cards, subscriptions, transactions } = parentData;

    return (
      <div className="p-6 max-w-7xl mx-auto space-y-8 animate-in fade-in duration-300">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-2 text-sm text-gray-500 mb-2">
              <button onClick={() => fetchDashboard()} className="hover:text-blue-600 flex items-center gap-1"><Home className="h-4 w-4"/> Dashboard</button>
              <ChevronRight className="h-3 w-3" />
              <span>Familienseite / Elternaccount</span>
            </div>
            <h1 className="text-3xl font-bold text-gray-900">{parent.name}</h1>
            <p className="text-gray-500">{parent.email}</p>
          </div>
          
          <div className="bg-white p-4 rounded-2xl shadow-sm border border-gray-200 flex items-center gap-6">
            <div>
              <p className="text-sm font-medium text-gray-500">Aktuelles Guthaben</p>
              <h2 className={`text-3xl font-bold ${parent.balance < 0 ? 'text-red-600' : 'text-emerald-600'}`}>
                {parent.balance.toFixed(2)} €
              </h2>
            </div>
            <button 
              onClick={() => setDepositModalData(parent.id)}
              className="p-2.5 bg-emerald-50 text-emerald-600 hover:bg-emerald-100 rounded-xl transition-colors flex flex-col items-center justify-center gap-1 border border-emerald-100"
              title="Guthaben manuell aufladen"
            >
              <Plus className="h-5 w-5" />
              <span className="text-[10px] font-bold uppercase tracking-wider">Aufladen</span>
            </button>
          </div>
        </div>

        <div className="space-y-6">
          <h2 className="text-xl font-semibold text-gray-800 flex items-center gap-2">
            <Users className="h-5 w-5 text-blue-500"/> Schüler, Karten & Abonnements
          </h2>
          
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {students.map(student => {
              const studentCards = cards.filter(c => c.studentId === student.id);
              const studentAbos = subscriptions.filter(a => a.studentId === student.id);

              return (
                <div key={student.id} className="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                  <div className="bg-gray-50 p-4 border-b border-gray-200 flex justify-between items-center">
                    <div>
                      <h3 className="text-lg font-bold text-gray-900">{student.name}</h3>
                      <p className="text-sm text-gray-500">Klasse {student.grade}</p>
                    </div>
                    <button onClick={() => editStudent(student.id)} className="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg">
                      <Edit2 className="h-4 w-4" />
                    </button>
                  </div>
                  
                  <div className="p-4 space-y-6">
                    <div>
                      <h4 className="text-xs font-bold tracking-wider text-gray-400 uppercase mb-3">Zugeordnete Karten</h4>
                      {studentCards.length === 0 ? <p className="text-sm text-gray-500">Keine Karten vorhanden.</p> : (
                        <div className="space-y-3">
                          {studentCards.map(card => (
                            <div key={card.id} className="flex items-center justify-between p-3 rounded-xl border border-gray-100 bg-white">
                              <div className="flex items-center gap-3">
                                <CreditCard className={`h-5 w-5 ${card.status === 'Aktiv' ? 'text-emerald-500' : 'text-red-500'}`} />
                                <div>
                                  <p className="font-mono font-medium text-gray-900">{card.cardNumber !== '-' ? card.cardNumber : 'Noch nicht zugewiesen'}</p>
                                  <p className={`text-xs font-semibold ${card.status === 'Aktiv' ? 'text-emerald-600' : card.status === 'Bestellt' ? 'text-purple-600' : 'text-red-600'}`}>{card.status}</p>
                                </div>
                              </div>
                              <div className="flex gap-2">
                                {card.status === 'Aktiv' ? (
                                  <>
                                    <button onClick={() => updateCardStatus(card.id, 'Gesperrt')} className="text-xs px-3 py-1.5 bg-amber-50 text-amber-700 hover:bg-amber-100 rounded-lg font-medium flex items-center gap-1 transition-colors">
                                      <Ban className="h-3 w-3"/> Sperren
                                    </button>
                                  </>
                                ) : card.status === 'Bestellt' ? (
                                  <button onClick={() => assignCardNumber(card.id, student.name)} className="text-xs px-3 py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-100 rounded-lg font-medium flex items-center gap-1 transition-colors">
                                    <CheckCircle className="h-3 w-3"/> Zuweisen
                                  </button>
                                ) : (
                                  <button onClick={() => updateCardStatus(card.id, 'Aktiv')} className="text-xs px-3 py-1.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 rounded-lg font-medium flex items-center gap-1 transition-colors">
                                    <CheckCircle className="h-3 w-3"/> Entsperren
                                  </button>
                                )}
                              </div>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>

                    <div>
                      <h4 className="text-xs font-bold tracking-wider text-gray-400 uppercase mb-3">Abonnements</h4>
                      {(() => {
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);
                        const visibleAbos = studentAbos.filter(abo => {
                          if (!abo.endDate) return true;
                          const endDate = new Date(abo.endDate);
                          const isExpired = endDate < today;
                          // Verstecke das Abo, wenn es in der Vergangenheit liegt UND bereits bezahlt ist
                          return !(isExpired && abo.status === 'Bezahlt');
                        });

                        if (visibleAbos.length === 0) {
                          return <p className="text-sm text-gray-500">Keine aktiven oder unbezahlten Abos vorhanden.</p>;
                        }

                        return (
                          <div className="space-y-3">
                            {visibleAbos.map(abo => (
                              <div key={abo.id} className="flex flex-col sm:flex-row sm:items-start justify-between p-3 rounded-xl border border-gray-100 bg-white gap-3 hover:shadow-sm transition-shadow">
                                <div className="flex-1">
                                  <div className="flex items-center gap-2 mb-1">
                                    <p className="font-semibold text-gray-900">{getAboLabel(abo.planName)}</p>
                                    <span className={`text-[10px] px-2 py-0.5 rounded-full font-medium ${abo.status === 'Bezahlt' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}>
                                      {abo.status}
                                    </span>
                                  </div>
                                  <div className="text-xs text-gray-500 space-y-0.5 mt-1">
                                    <p>Gültig: {formatDate(abo.startDate)} - {formatDate(abo.endDate) || 'Unbegrenzt'}</p>
                                    <p>Tage: {formatWeekdays(abo.weekdays)}</p>
                                  </div>
                                  {abo.status === 'Bezahlt' && abo.transactionNr && (
                                    <span className="text-[10px] text-gray-500 border border-gray-200 bg-white px-2 py-0.5 rounded font-mono mt-2 inline-block">
                                      Ref: {abo.transactionNr}
                                    </span>
                                  )}
                                </div>
                                <div className="flex gap-2 sm:mt-0 mt-2 self-end sm:self-auto">
                                  {abo.status === 'Unbezahlt' && (
                                    <button onClick={() => setPayAboModalData(abo.id)} className="p-2 text-blue-600 hover:bg-blue-50 hover:text-blue-700 rounded-lg transition-colors border border-transparent hover:border-blue-100" title="Als bezahlt markieren">
                                      <Check className="h-4 w-4"/>
                                    </button>
                                  )}
                                  <button onClick={() => deleteAbo(abo.id)} className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors border border-transparent hover:border-red-100" title="Abo löschen">
                                    <Trash2 className="h-4 w-4"/>
                                  </button>
                                </div>
                              </div>
                            ))}
                          </div>
                        );
                      })()}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        <div className="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
          <div className="p-6 border-b border-gray-200"><h2 className="text-xl font-semibold text-gray-800 flex items-center gap-2"><RefreshCw className="h-5 w-5 text-indigo-500"/> Transaktionsverlauf</h2></div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm text-gray-500">
              <thead className="bg-gray-50 text-xs text-gray-700 uppercase">
                <tr><th className="px-6 py-4">Datum</th><th className="px-6 py-4">Beschreibung</th><th className="px-6 py-4 text-right">Betrag</th><th className="px-6 py-4">Status</th><th className="px-6 py-4 text-right">Aktion</th></tr>
              </thead>
              <tbody>
                {transactions.length === 0 ? (
                  <tr><td colSpan="5" className="px-6 py-8 text-center text-gray-500">Keine Transaktionen gefunden.</td></tr>
                ) : (
                  transactions.map(tx => (
                    <tr key={tx.id} className="border-b border-gray-100">
                      <td className="px-6 py-4 whitespace-nowrap">{tx.date}</td>
                      <td className="px-6 py-4 font-medium text-gray-900">{tx.description}</td>
                      <td className={`px-6 py-4 text-right font-semibold ${tx.amount < 0 ? 'text-gray-900' : 'text-emerald-600'}`}>{tx.amount > 0 ? '+' : ''}{tx.amount.toFixed(2)} €</td>
                      <td className="px-6 py-4"><span className={`px-2 py-0.5 rounded text-xs font-medium ${tx.status === 'Erstattet' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'}`}>{tx.status}</span></td>
                      <td className="px-6 py-4 text-right">
                        {tx.amount < 0 && tx.status !== 'Erstattet' && (
                          <button onClick={() => refundTransaction(tx.id)} className="text-indigo-600 hover:underline text-xs font-medium">Zurückerstatten</button>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    );
  };

  const PendingCardsList = () => {
    return (
      <div className="p-6 max-w-7xl mx-auto space-y-6 animate-in fade-in duration-300">
        <div className="flex items-center gap-2 text-sm text-gray-500 mb-2">
          <button onClick={() => fetchDashboard()} className="hover:text-blue-600 flex items-center gap-1"><Home className="h-4 w-4"/> Dashboard</button>
          <ChevronRight className="h-3 w-3" /><span>Ausstehende Karten</span>
        </div>
        <h2 className="text-2xl font-bold text-gray-800 flex items-center gap-3"><Clock className="h-6 w-6 text-purple-600" /> Ausstehende Kartenbestellungen</h2>

        <div className="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm text-gray-500">
              <thead className="bg-gray-50 text-xs text-gray-700 uppercase">
                <tr><th className="px-6 py-4">Bestelldatum</th><th className="px-6 py-4">Schüler</th><th className="px-6 py-4">Klasse</th><th className="px-6 py-4">Elternaccount</th><th className="px-6 py-4 text-right">Aktion</th></tr>
              </thead>
              <tbody>
                {pendingCards.length === 0 ? (
                  <tr><td colSpan="5" className="px-6 py-8 text-center text-gray-500">Keine ausstehenden Karten.</td></tr>
                ) : (
                  pendingCards.map(card => (
                    <tr key={card.id} className="border-b border-gray-100 hover:bg-gray-50/50">
                      <td className="px-6 py-4">{card.orderDate || 'Unbekannt'}</td>
                      <td className="px-6 py-4 font-medium text-gray-900">{card.studentName}</td>
                      <td className="px-6 py-4">{card.grade || '-'}</td>
                      <td className="px-6 py-4"><button onClick={() => loadParentDetail(card.parentId)} className="text-blue-600 hover:underline font-medium">{card.parentName}</button></td>
                      <td className="px-6 py-4 text-right"><button onClick={() => assignCardNumber(card.id, card.studentName)} className="text-xs px-4 py-2 bg-purple-50 text-purple-700 hover:bg-purple-100 rounded-lg font-medium">Zuweisen</button></td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    );
  };

  const UnpaidAbosList = () => {
    return (
      <div className="p-6 max-w-7xl mx-auto space-y-6 animate-in fade-in duration-300">
        <div className="flex items-center gap-2 text-sm text-gray-500 mb-2">
          <button onClick={() => fetchDashboard()} className="hover:text-blue-600 flex items-center gap-1"><Home className="h-4 w-4"/> Dashboard</button>
          <ChevronRight className="h-3 w-3" /><span>Unbezahlte Abos</span>
        </div>
        <h2 className="text-2xl font-bold text-gray-800 flex items-center gap-3"><AlertCircle className="h-6 w-6 text-amber-600" /> Unbezahlte Abonnements</h2>

        <div className="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm text-gray-500">
              <thead className="bg-gray-50 text-xs text-gray-700 uppercase">
                <tr><th className="px-6 py-4">Abo-Typ</th><th className="px-6 py-4">Schüler</th><th className="px-6 py-4">Klasse</th><th className="px-6 py-4">Familienseite</th><th className="px-6 py-4 text-right">Aktion</th></tr>
              </thead>
              <tbody>
                {unpaidAbos.length === 0 ? (
                  <tr><td colSpan="5" className="px-6 py-8 text-center text-gray-500">Alle Abos sind bezahlt.</td></tr>
                ) : (
                  unpaidAbos.map(abo => (
                    <tr key={abo.id} className="border-b border-gray-100 hover:bg-gray-50/50">
                      <td className="px-6 py-4 font-medium text-gray-900">{getAboLabel(abo.planName)}</td>
                      <td className="px-6 py-4 font-medium text-gray-900">{abo.studentName}</td>
                      <td className="px-6 py-4">{abo.grade || '-'}</td>
                      <td className="px-6 py-4"><button onClick={() => loadParentDetail(abo.parentId)} className="text-blue-600 hover:underline font-medium">{abo.parentName}</button></td>
                      <td className="px-6 py-4 text-right"><button onClick={() => setPayAboModalData(abo.id)} className="text-xs px-4 py-2 bg-blue-50 text-blue-700 hover:bg-blue-100 rounded-lg font-medium">Als bezahlt markieren</button></td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    );
  };

  if (!isAuthenticated) return <LoginScreen />;
  if (isLoading && currentView.type === 'dashboard' && !dashboardData) return (
    <div className="min-h-screen bg-slate-50 flex flex-col items-center justify-center space-y-4">
      <Loader2 className="h-12 w-12 text-blue-600 animate-spin" />
      <p className="text-gray-500 font-medium animate-pulse">Lade Systemdaten...</p>
    </div>
  );

  if (error) return (
    <div className="min-h-screen bg-slate-50 flex flex-col items-center justify-center p-4">
      <div className="bg-white p-8 rounded-2xl shadow-sm border border-red-100 max-w-md w-full text-center space-y-4">
        <div className="bg-red-50 p-4 rounded-full inline-block"><AlertCircle className="h-10 w-10 text-red-600" /></div>
        <h2 className="text-xl font-bold text-gray-900">Verbindungsfehler</h2>
        <p className="text-gray-500">{error}</p>
        <button onClick={fetchDashboard} className="mt-4 px-6 py-2.5 bg-gray-900 text-white rounded-xl hover:bg-gray-800">Erneut versuchen</button>
      </div>
    </div>
  );

  return (
    <div className="min-h-screen bg-slate-50 font-sans text-gray-900">
      <Navigation />
      <SearchOverlay />
      
      {isLoading && (
        <div className="fixed inset-x-0 top-16 h-1 bg-blue-100 overflow-hidden z-50">
          <div className="h-full bg-blue-500 w-1/3 animate-[slideRight_1s_infinite_linear]"></div>
        </div>
      )}

      <main className={`pb-12 transition-opacity duration-200 ${isLoading ? 'opacity-60' : 'opacity-100'}`}>
        {currentView.type === 'dashboard' && <Dashboard />}
        {currentView.type === 'parentDetail' && <ParentDetail />}
        {currentView.type === 'pendingCards' && <PendingCardsList />}
        {currentView.type === 'unpaidAbos' && <UnpaidAbosList />}
      </main>

      {/* RENDER MODALS */}
      {assignModalData && (
        <AssignCardModal 
          assignData={assignModalData} 
          onClose={() => setAssignModalData(null)} 
          onComplete={submitAssignedCard} 
        />
      )}

      {payAboModalData && (
        <MarkAboPaidModal 
          aboId={payAboModalData} 
          onClose={() => setPayAboModalData(null)} 
          onComplete={markAboPaid} 
        />
      )}

      {depositModalData && (
        <DepositModal 
          parentId={depositModalData} 
          onClose={() => setDepositModalData(null)} 
          onComplete={submitDeposit} 
        />
      )}
    </div>
  );
}