/*
 * MensaManager - Digitale Schulverpflegung
 * Copyright (C) 2026 Lukas Trausch
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see [https://www.gnu.org/licenses/](https://www.gnu.org/licenses/).
 */

import React, { useState, useEffect, useRef } from 'react';
import HCaptcha from "@hcaptcha/react-hcaptcha";
import { 
  Search, Users, CreditCard, CheckCircle, 
  X, AlertCircle, Loader2, ArrowRightLeft, 
  Filter, Check, Camera, RotateCcw, Lock, LogOut
} from 'lucide-react';
import { API_BASE } from './runtimeConfig';

// --- ASSIGN CARD MODAL (CAMERA & SCANNER) ---
const AssignCardModal = ({ assignData, onClose, onComplete, isLoading }) => {
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
    if (!isLoading) {
    onComplete(assignData.cardId, barcode, faceImg);
    }
  };

  return (
    <div className="fixed inset-0 z-[100] bg-slate-900/90 backdrop-blur-sm flex justify-center items-center p-4 animate-in fade-in duration-200">
      <div className="bg-white w-full max-w-xl rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh] animate-in slide-in-from-bottom-8 zoom-in-95 duration-300">
        <div className="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
          <div>
            <h3 className="font-bold text-gray-900">Karte zuweisen</h3>
            <p className="text-xs text-gray-500">Für Schüler: <span className="font-semibold text-gray-700">{assignData.studentName}</span></p>
          </div>
          <button onClick={onClose} disabled={isLoading} className="p-2 bg-white rounded-full text-gray-400 hover:text-gray-600 shadow-sm border border-gray-100 disabled:opacity-50">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto p-6 flex flex-col items-center">
          {step === 'face' && (
            <div className="w-full flex flex-col items-center space-y-4 animate-in fade-in slide-in-from-right-4">
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
            <div className="w-full flex flex-col items-center space-y-4 animate-in fade-in slide-in-from-right-4">
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
            <div className="w-full flex flex-col items-center space-y-4 animate-in fade-in slide-in-from-right-4">
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
            <div className="w-full flex flex-col items-center space-y-6 animate-in fade-in zoom-in-95">
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
                <button onClick={() => setStep('barcode')} disabled={isLoading} className="flex-1 py-3 px-4 border border-gray-300 rounded-xl text-gray-700 font-medium hover:bg-gray-50 disabled:opacity-50">Neu scannen</button>
                <button onClick={handleSubmit} disabled={isLoading} className="flex-1 py-3 px-4 bg-blue-600 text-white rounded-xl font-bold hover:bg-blue-700 shadow-sm flex items-center justify-center gap-2 disabled:opacity-70 transition-all">
                  {isLoading ? <Loader2 className="h-5 w-5 animate-spin"/> : <Check className="h-5 w-5"/>}
                  {isLoading ? 'Wird zugewiesen...' : 'Zuweisen'}
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

// --- HAUPT APP ---
export default function TeacherApp() {
  // --- AUTH STATE ---
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [loginEmail, setLoginEmail] = useState('');
  const [loginPassword, setLoginPassword] = useState('');
  const [captchaValue, setCaptchaValue] = useState(null);
  const [captchaSiteKey, setCaptchaSiteKey] = useState('');
  const [loginError, setLoginError] = useState('');
  const [isLoggingIn, setIsLoggingIn] = useState(false);
  const [authChecking, setAuthChecking] = useState(true);
  const [csrfToken, setCsrfToken] = useState('');

  // --- APP STATE ---
  const [activeTab, setActiveTab] = useState('ausgeben'); 
  const [students, setStudents] = useState([]);
  const [loading, setLoading] = useState(false);
  
  // Filter States
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedClass, setSelectedClass] = useState('Alle');
  const [availableClasses, setAvailableClasses] = useState([]);

  // Modal States
  const [assignModalData, setAssignModalData] = useState(null);
  const [returnStudentData, setReturnStudentData] = useState(null);
  const [deleteStudent, setDeleteStudent] = useState(false); // NEU: State für Checkbox
  const [actionLoading, setActionLoading] = useState(false);
  const [notification, setNotification] = useState(null);

  // --- AUTH LOGIK ---
  useEffect(() => {
    fetchLoginConfig();
    checkAuthStatus();
  }, []);

  const fetchLoginConfig = async () => {
    try {
      const response = await fetch(`${API_BASE}/login.php?config=1`, { credentials: 'include' });
      const result = await response.json();
      if (result.captchaSiteKey) {
        setCaptchaSiteKey(result.captchaSiteKey);
      }
    } catch (error) {
      console.error("Login config failed:", error);
    }
  };

  const checkAuthStatus = async () => {
    try {
      const response = await fetch(`${API_BASE}/login.php?check=1`, { credentials: 'include' });
      const result = await response.json();
      
      if (result.success) {
        if (result.csrfToken) setCsrfToken(result.csrfToken);
        setIsAuthenticated(true);
        fetchData();
      }
    } catch (error) {
      console.error("Auth check failed:", error);
    } finally {
      setAuthChecking(false);
    }
  };

  const handleLogin = async (e) => {
    e.preventDefault();
    setLoginError('');
    
    if (!captchaValue) {
      setLoginError('Bitte lösen Sie das Captcha (Klick auf den Button).');
      return;
    }

    setIsLoggingIn(true);
    try {
      const formData = new FormData();
      formData.append('email', loginEmail);
      formData.append('password', loginPassword);
      formData.append('h-captcha-response', captchaValue);

      const response = await fetch(`${API_BASE}/login.php`, { 
        method: 'POST', 
        body: formData,
        credentials: 'include'
      });
      
      const result = await response.json();

      if (result.success) {
        if (result.csrfToken) setCsrfToken(result.csrfToken);
        setIsAuthenticated(true);
        fetchData();
      } else {
        setLoginError(result.error || 'Login fehlgeschlagen');
      }
    } catch (err) {
      setLoginError('Netzwerk- oder Serverfehler beim Login.');
    } finally {
      setIsLoggingIn(false);
    }
  };

  const handleLogout = async () => {
    try {
      const response = await fetch(`${API_BASE}/logout.php`, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        credentials: 'include'
      })
      .then(async response => {
        if (response.status === 502) {
          console.warn("Nginx 502 Header-Limit abgefangen. Session wurde sicher gelöscht.");
          window.location.reload();
          return;
        }
                  
        const data = await response.json().catch(() => null);
        if (!response.ok || (data?.status !== 'success' && data?.success !== true)) {
          throw new Error(data?.message || data?.error || 'Logout fehlgeschlagen.');
        }
        window.location.reload();
      })
      setIsAuthenticated(false);
      setDashboardData(null);
      setCsrfToken('');
    } catch (e) {
      console.error("Logout error", e);
      setLoginError(e.message || 'Logout fehlgeschlagen.');
    }
  };

  // --- DATEN LADEN ---
  const fetchData = async () => {
    setLoading(true);
    try {
      const resPending = await fetch(`${API_BASE}/data.php?action=pending`, { credentials: 'include' });
      if (!resPending.ok) {
        if (resPending.status === 403) setIsAuthenticated(false);
        throw new Error(`HTTP Fehler: ${resPending.status}`);
      }
      const dataPending = await resPending.json();
      if (dataPending.error) throw new Error(dataPending.error);
      if (dataPending.csrfToken) setCsrfToken(dataPending.csrfToken);

      const resActive = await fetch(`${API_BASE}/data.php?action=active_cards`, { credentials: 'include' });
      if (!resActive.ok) {
        if (resActive.status === 403) setIsAuthenticated(false);
        throw new Error(`HTTP Fehler: ${resActive.status}`);
      }
      const dataActive = await resActive.json();
      if (dataActive.error) throw new Error(dataActive.error);
      if (dataActive.csrfToken) setCsrfToken(dataActive.csrfToken);

      // Fasse die API Daten zusammen und passe sie an das UI an
      const pendingCards = (dataPending.pendingCards || []).map(s => ({ 
        ...s, 
        id: s.studentId, 
        hasCard: false, 
        name: s.studentName, 
        class: s.grade 
      }));
      const activeCards = (dataActive.activeCards || []).map(s => ({ 
        ...s, 
        id: s.studentId, 
        hasCard: true, 
        name: s.studentName, 
        class: s.grade 
      }));
      
      const allStudents = [...pendingCards, ...activeCards];
      setStudents(allStudents);

      // Dynamisch alle vorhandenen Klassen extrahieren und sortieren
      const classes = [...new Set(allStudents.map(s => s.class).filter(Boolean))].sort();
      setAvailableClasses(['Alle', ...classes]);

    } catch (error) {
      showNotification(error.message || 'Fehler beim Laden der Daten', 'error');
    } finally {
      setLoading(false);
    }
  };

  // --- AKTIONEN ---
  const handleAssignCard = async (studentId, barcode, faceImg) => {
    setActionLoading(true);
    try {
      const formData = new FormData();
      // Verwende die korrekten Namen, die dein funktionierendes Backend erwartet
      formData.append('action', 'assignCardNumber');
      formData.append('cardId', `pending_${studentId}`); // Backend erwartet Präfix
      formData.append('cardNumber', barcode);
      if (faceImg) formData.append('faceData', faceImg); 

      const response = await fetch(`${API_BASE}/actions.php`, { 
        method: 'POST', 
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData,
        credentials: 'include'
      });
      
      if (!response.ok) {
        if(response.status === 403) setIsAuthenticated(false);
        let errorMsg = `HTTP Fehler: ${response.status}`;
        try {
          const errData = await response.json();
          if (errData.error) errorMsg = errData.error;
        } catch (e) {}
        throw new Error(errorMsg);
      }

      const result = await response.json();
      
      if(!result.success) throw new Error(result.error || 'Fehler beim Zuweisen der Karte.');

      // FEHLERBEHEBUNG: Keine UID als ID in den lokalen State speichern!
      // Wir laden die echten IDs (card_id) frisch von der Datenbank:
      await fetchData();
      
      const studentName = students.find(s => s.id === studentId)?.name || 'dem Schüler';
      showNotification(`Karte erfolgreich an ${studentName} ausgegeben!`, 'success');
      setAssignModalData(null);

    } catch (error) {
      showNotification(error.message, 'error');
    } finally {
      setActionLoading(false);
    }
  };

  const handleReturnCard = async () => {
    setActionLoading(true);
    try {
      const formData = new FormData();
      formData.append('action', 'collectCard');
      formData.append('cardId', returnStudentData.cardId || returnStudentData.id);
      formData.append('studentId', returnStudentData.studentId);
      if (deleteStudent) formData.append('deleteStudent', '1');

      const response = await fetch(`${API_BASE}/actions.php`, { 
        method: 'POST', 
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData,
        credentials: 'include'
      });
      
      if (!response.ok) {
        if(response.status === 403) setIsAuthenticated(false);
        let errorMsg = `HTTP Fehler: ${response.status}`;
        try {
          const errData = await response.json();
          if (errData.error) errorMsg = errData.error;
        } catch (e) {}
        throw new Error(errorMsg);
      }

      const result = await response.json();
      
      if(!result.success) throw new Error(result.error || 'Fehler beim Einsammeln der Karte.');

      // FEHLERBEHEBUNG: Frisch neu laden für einen sauberen State
      await fetchData();
      
      showNotification(`Karte von ${returnStudentData.name} erfolgreich eingesammelt!`, 'success');
      setReturnStudentData(null);
      setDeleteStudent(false);

    } catch (error) {
      showNotification(error.message, 'error');
    } finally {
      setActionLoading(false);
    }
  };

  // --- HILFSFUNKTIONEN ---
  const showNotification = (message, type) => {
    setNotification({ message, type });
    setTimeout(() => setNotification(null), 3500);
  };

  const openModal = (student) => {
    if (activeTab === 'ausgeben') {
      setAssignModalData({ cardId: student.id, studentName: student.name });
    } else {
      setReturnStudentData(student);
      setDeleteStudent(!student.hasActiveAbo); 
    }
  };

  // --- RENDER CHECK AUTH ---
  if (authChecking) {
     return (
       <div className="min-h-screen bg-slate-50 flex flex-col justify-center items-center">
         <Loader2 className="w-10 h-10 text-blue-600 animate-spin mb-4" />
         <p className="text-slate-500">Überprüfe Sitzung...</p>
       </div>
     );
  }

  // --- RENDER LOGIN VIEW ---
  if (!isAuthenticated) {
    return (
      <div className="min-h-screen bg-slate-50 flex flex-col justify-center py-12 px-4 sm:px-6 lg:px-8 font-sans">
        <div className="sm:mx-auto sm:w-full sm:max-w-md">
          <div className="flex justify-center">
            <div className="w-16 h-16 bg-blue-600 rounded-2xl flex items-center justify-center shadow-lg">
              <Lock className="w-8 h-8 text-white" />
            </div>
          </div>
          <h2 className="mt-6 text-center text-3xl font-extrabold text-slate-900">
            Lehrer Login
          </h2>
          <p className="mt-2 text-center text-sm text-slate-600">
            Kartenverwaltung für die Schulmensa
          </p>
        </div>

        <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
          <div className="bg-white py-8 px-4 shadow-xl shadow-slate-200/50 sm:rounded-2xl sm:px-10 border border-slate-100">
            <form className="space-y-6" onSubmit={handleLogin}>
              {loginError && (
                <div className="bg-red-50 border-l-4 border-red-500 p-4 rounded-md flex items-start animate-in fade-in slide-in-from-top-2">
                  <AlertCircle className="h-5 w-5 text-red-500 mr-2 shrink-0 mt-0.5" />
                  <p className="text-sm text-red-700">{loginError}</p>
                </div>
              )}

              <div>
                <label className="block text-sm font-medium text-slate-700">E-Mail Adresse</label>
                <div className="mt-1">
                  <input
                    type="email"
                    required
                    value={loginEmail}
                    onChange={(e) => setLoginEmail(e.target.value)}
                    className="appearance-none block w-full px-3 py-3 border border-slate-300 rounded-xl shadow-sm placeholder-slate-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                    placeholder="lehrer@schule.de"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-700">Passwort</label>
                <div className="mt-1">
                  <input
                    type="password"
                    required
                    value={loginPassword}
                    onChange={(e) => setLoginPassword(e.target.value)}
                    className="appearance-none block w-full px-3 py-3 border border-slate-300 rounded-xl shadow-sm placeholder-slate-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                  />
                </div>
              </div>

              <div className="flex justify-center transform scale-90 sm:scale-100 origin-center w-full">
                {captchaSiteKey ? (
                  <HCaptcha
                    sitekey={captchaSiteKey}
                    onVerify={(val) => setCaptchaValue(val)}
                  />
                ) : (
                  <p className="text-sm text-slate-500">Captcha wird geladen...</p>
                )}
              </div>

              <div>
                <button
                  type="submit"
                  disabled={isLoggingIn}
                  className="w-full flex justify-center py-3 px-4 border border-transparent rounded-xl shadow-sm text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-70 transition-colors"
                >
                  {isLoggingIn ? <Loader2 className="w-5 h-5 animate-spin" /> : 'Anmelden'}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    );
  }

  // --- FILTER LOGIK (MAIN APP) ---
  const filteredStudents = students.filter(student => {
    const matchesTab = activeTab === 'ausgeben' ? !student.hasCard : student.hasCard;
    if (!matchesTab) return false;
    
    // Fallback falls ein Schüler keine Klasse eingetragen hat
    const studentClass = student.class || 'Unbekannt';
    const matchesClass = selectedClass === 'Alle' || studentClass === selectedClass;
    if (!matchesClass) return false;
    
    const matchesSearch = student.name.toLowerCase().includes(searchQuery.toLowerCase());
    if (!matchesSearch) return false;
    
    return true;
  });

  // --- RENDER MAIN APP ---
  return (
    <div className="min-h-screen bg-gray-50 text-slate-800 font-sans">
      
      {/* HEADER & TABS */}
      <div className="bg-white shadow-sm sticky top-0 z-10">
        <div className="max-w-md mx-auto px-4 pt-4 pb-2">
          <div className="flex items-center justify-between mb-4">
            <h1 className="text-xl font-bold flex items-center gap-2 text-slate-900">
              <ArrowRightLeft className="w-6 h-6 text-blue-600" />
              Mensa Karten
            </h1>
            <button onClick={handleLogout} className="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-full transition-colors" title="Abmelden">
              <LogOut className="w-5 h-5" />
            </button>
          </div>
          
          <div className="flex bg-slate-100 p-1 rounded-xl">
            <button
              onClick={() => setActiveTab('ausgeben')}
              className={`flex-1 flex items-center justify-center gap-2 py-2.5 rounded-lg text-sm font-medium transition-all ${
                activeTab === 'ausgeben' 
                  ? 'bg-white text-blue-700 shadow-sm' 
                  : 'text-slate-500 hover:text-slate-700'
              }`}
            >
              <CreditCard className="w-4 h-4" />
              Ausgeben
            </button>
            <button
              onClick={() => setActiveTab('einsammeln')}
              className={`flex-1 flex items-center justify-center gap-2 py-2.5 rounded-lg text-sm font-medium transition-all ${
                activeTab === 'einsammeln' 
                  ? 'bg-white text-blue-700 shadow-sm' 
                  : 'text-slate-500 hover:text-slate-700'
              }`}
            >
              <CheckCircle className="w-4 h-4" />
              Einsammeln
            </button>
          </div>
        </div>
      </div>

      <main className="max-w-md mx-auto p-4 pb-24">
        {/* FILTER BEREICH */}
        <div className="flex gap-2 mb-4">
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" />
            <input
              type="text"
              placeholder="Schüler suchen..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-10 pr-4 py-3 bg-white border border-slate-200 rounded-xl text-base focus:outline-none focus:ring-2 focus:ring-blue-500 transition-shadow"
            />
          </div>
          <div className="relative w-1/3">
            <Filter className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
            <select
              value={selectedClass}
              onChange={(e) => setSelectedClass(e.target.value)}
              className="w-full pl-9 pr-4 py-3 bg-white border border-slate-200 rounded-xl text-base appearance-none focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              {availableClasses.map(c => (
                <option key={c} value={c}>{c === 'Alle' ? 'Klasse' : c}</option>
              ))}
            </select>
          </div>
        </div>

        {/* LISTE */}
        {loading ? (
          <div className="flex flex-col items-center justify-center py-12 text-slate-400">
            <Loader2 className="w-8 h-8 animate-spin mb-2" />
            <p>Lade Schülerdaten...</p>
          </div>
        ) : filteredStudents.length === 0 ? (
          <div className="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-300">
            <Users className="w-12 h-12 text-slate-300 mx-auto mb-3" />
            <p className="text-slate-500 font-medium">Keine Schüler gefunden</p>
            <p className="text-sm text-slate-400 mt-1">
              {searchQuery ? 'Überprüfe deine Suche.' : 'In dieser Klasse gibt es nichts zu tun.'}
            </p>
          </div>
        ) : (
          <div className="space-y-3">
            {filteredStudents.map(student => (
              <div 
                key={student.id}
                onClick={() => openModal(student)}
                className="bg-white p-4 rounded-xl shadow-sm border border-slate-100 flex items-center justify-between active:scale-[0.98] transition-transform cursor-pointer hover:border-blue-200"
              >
                <div>
                  <div className="flex items-center gap-2">
                    <span className="font-semibold text-slate-900">{student.name}</span>
                    <span className="bg-slate-100 text-slate-600 text-xs px-2 py-0.5 rounded-full font-medium">
                      {student.class || '?'}
                    </span>
                  </div>
                  <p className="text-sm text-slate-400 mt-1 line-clamp-1">
                    Eltern: {student.parentName || 'Unbekannt'}
                  </p>
                </div>
                <div>
                  {activeTab === 'ausgeben' ? (
                    <div className="w-10 h-10 rounded-full bg-blue-50 flex items-center justify-center group-hover:bg-blue-100">
                      <Camera className="w-5 h-5 text-blue-600" />
                    </div>
                  ) : (
                    <div className="w-10 h-10 rounded-full bg-emerald-50 flex items-center justify-center group-hover:bg-emerald-100">
                      <Check className="w-5 h-5 text-emerald-600" />
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </main>

      {/* NOTIFICATION TOAST */}
      {notification && (
        <div className="fixed bottom-4 left-4 right-4 z-50 flex justify-center animate-in slide-in-from-bottom-5">
          <div className={`px-4 py-3 rounded-xl shadow-lg flex items-center gap-2 max-w-sm w-full ${
            notification.type === 'success' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'
          }`}>
            {notification.type === 'success' ? <CheckCircle className="w-5 h-5 shrink-0" /> : <AlertCircle className="w-5 h-5 shrink-0" />}
            <p className="font-medium text-sm break-words">{notification.message}</p>
          </div>
        </div>
      )}

      {/* MODALS */}
      
      {/* 1. Ausgeben: Vollbild Kamera Scanner */}
      {assignModalData && (
        <AssignCardModal
          assignData={assignModalData}
          onClose={() => setAssignModalData(null)}
          onComplete={handleAssignCard}
          isLoading={actionLoading}
        />
      )}

      {/* 2. Einsammeln: Standard Bottom Sheet */}
      {returnStudentData && (
        <div className="fixed inset-0 z-40 flex items-end sm:items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 animate-in fade-in">
          <div className="bg-white w-full max-w-md rounded-3xl p-6 shadow-2xl animate-in slide-in-from-bottom-10 sm:slide-in-from-bottom-0 sm:zoom-in-95">
            <div className="flex justify-between items-start mb-6">
              <div>
                <h2 className="text-xl font-bold text-slate-900">Karte einsammeln</h2>
                <p className="text-slate-500 mt-1">{returnStudentData.name} • Klasse {returnStudentData.class}</p>
              </div>
              <button onClick={() => setReturnStudentData(null)} className="p-2 bg-slate-100 rounded-full text-slate-500 hover:bg-slate-200 transition-colors">
                <X className="w-5 h-5" />
              </button>
            </div>

            {returnStudentData.hasActiveAbo && (
              <div className="bg-red-50 border border-red-100 rounded-xl p-4 mb-4 flex items-start gap-3">
                <AlertCircle className="w-5 h-5 text-red-600 shrink-0 mt-0.5" />
                <div>
                  <p className="text-red-900 font-medium">Achtung: Aktives Abo!</p>
                  <p className="text-red-700 text-sm mt-1">
                    Dieser Schüler hat noch ein bezahltes, aktives Abo. Bitte weise die Eltern darauf hin, bevor die Karte eingesammelt wird.
                  </p>
                </div>
              </div>
            )}

            <div className="bg-orange-50 border border-orange-100 rounded-xl p-4 mb-6 flex items-start gap-3">
              <AlertCircle className="w-5 h-5 text-orange-600 shrink-0 mt-0.5" />
              <div className="w-full">
                <p className="text-orange-900 font-medium">Karte wirklich einsammeln?</p>
                <p className="text-orange-700 text-sm mt-1 mb-3">
                  Die Karte wird deaktiviert und das Kartenpfand automatisch dem Elternkonto gutgeschrieben.
                </p>
                
                <label className={`flex items-start gap-3 p-3 rounded-lg border ${returnStudentData.hasActiveAbo ? 'opacity-50 cursor-not-allowed bg-orange-100/50 border-orange-200' : 'cursor-pointer hover:bg-orange-100/50 border-orange-200 bg-white/50'}`}>
                  <input 
                    type="checkbox" 
                    checked={deleteStudent} 
                    onChange={(e) => setDeleteStudent(e.target.checked)}
                    disabled={returnStudentData.hasActiveAbo}
                    className="w-5 h-5 mt-0.5 text-red-600 rounded border-orange-300 focus:ring-red-500 disabled:opacity-50"
                  />
                  <div>
                    <p className="font-medium text-orange-900 text-sm">Schüler komplett löschen</p>
                    <p className="text-xs text-orange-700 mt-0.5 leading-snug">
                      Entfernt den Schüler endgültig. Nur möglich, wenn keine aktiven Abos vorliegen.
                    </p>
                  </div>
                </label>
              </div>
            </div>

            <div className="flex gap-3">
              <button
                onClick={() => setReturnStudentData(null)}
                className="flex-1 py-4 rounded-xl bg-slate-100 text-slate-700 font-bold hover:bg-slate-200 transition-colors"
              >
                Abbrechen
              </button>
              <button
                onClick={handleReturnCard}
                disabled={actionLoading}
                className="flex-1 py-4 rounded-xl bg-red-600 text-white font-bold hover:bg-red-700 active:bg-red-800 disabled:opacity-70 flex justify-center items-center transition-colors"
              >
                {actionLoading ? <Loader2 className="w-6 h-6 animate-spin" /> : 'Einsammeln'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
