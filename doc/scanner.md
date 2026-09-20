# Scanner

Dans les établissements scolaires en France (écoles primaires, collèges, lycées), le matériel reprographique est principalement acquis via la centrale d'achat public **UGAP** ou par des marchés publics locaux des collectivités territoriales (communes, départements, régions).

## Marques et modèles les plus présents

- **Toshiba Tec :** Titulaire historique des marchés publics UGAP (très représenté avec la gamme **e-STUDIO**, ex. e-STUDIO 2021AC, 2525AC, e-339CS).
- **Canon :** Une des marques les plus déployées dans l'enseignement (gammes **imageRUNNER ADVANCE DX**, ex. C3525i, C3530i).
- **Ricoh :** Très présent dans les établissements du secondaire et du supérieur (gammes **IM C** et **MP C**).
- **Konica Minolta :** Très répandu dans les collectivités et écoles (gamme **bizhub**, ex. C258, C227, C368).
- **Kyocera** et **Sharp :** Également présents via des groupements d'achats régionaux.

## Interfaces pour récupérer les fichiers numérisés

Les photocopieurs/scanneurs d'entreprise (MFP) équipés de chargeurs de documents (ADF) fonctionnent quasi exclusivement en **mode Push** : l'utilisateur pose sa pile de feuilles, appuie sur le bouton du copieur, et la machine expédie le fichier numérisé (PDF multipage, TIFF ou JPEG) sur le réseau.

### SMTP (Scan-to-Email)

- *Fonctionnement :* Le scan est envoyé en pièce jointe d'un e-mail.
- *Côté programme :* Ton programme consulte une boîte e-mail dédiée (via IMAP/POP3) ou agit comme un serveur SMTP récepteur.
Disponibilité : Fonctionnalité standard intégrée de série sur la quasi-totalité des copieurs multifonctions professionnels depuis plus de 20 ans.

### FTP / FTPS

- *Fonctionnement :* Le copieur se connecte à un serveur FTP pour y déposer les fichiers.
- *Côté programme :* Tu peux embarquer un mini-serveur FTP au sein de ton application (ex. via `pyftpdlib` en Python) pour recevoir les scans directement en mémoire ou sur disque sans dépendre des droits SMB du système.
Disponibilité : Intégré de série sur pratiquement toutes les machines professionnelles, au même titre que le protocole SMB (dossier partagé).


### WebDAV / HTTP POST

- *Fonctionnement :* Les copieurs récents permettent l'envoi direct vers un endpoint HTTP/HTTPS ou un dossier WebDAV.
Disponibilité : Intégré sur la plupart des gammes récentes (sorties depuis 5 à 10 ans), mais souvent absent ou nécessitant des licences/extensions logicielles sur les modèles d'entrée de gamme ou plus anciens.
