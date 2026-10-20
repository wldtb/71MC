Imports System.Runtime.InteropServices
Imports System.Security.Principal

Public Class PageOtherTest

    Public Shared Function GetRandomHint() As String
        Return RandomOne(New String() {
            "欢迎使用 71MC！",
            "你可以在设置中检查 71MC 更新。",
            "点击顶部联机按钮，即可打开本机的联机工具。",
            "请定期备份你的 Minecraft 存档。"
        })
    End Function

    <StructLayout(LayoutKind.Sequential, Pack:=1)>
    Private Structure PrivilegeToken
        Public PrivilegeCount As Integer
        Public Luid As Long
        Public Attributes As Integer
    End Structure

    Private IsLoad As Boolean = False
    Private Shared IsMemoryOptimizeRunning As Boolean = False

#Region "初始化"

    Private Sub MeLoaded() Handles Me.Loaded
        If IsLoad Then Return
        IsLoad = True

        TextDownloadFolder.Text = Settings.Get(Of String)("CacheDownloadFolder")
        TextDownloadFolder.Validate()
        If Not TextDownloadFolder.IsValidated OrElse TextDownloadFolder.Text = "" Then
            TextDownloadFolder.Text = Paths.Base & "PCL\MyDownload\"
        End If
        TextDownloadFolder.Validate()
        TextDownloadName.Validate()
        StartButtonRefresh()
    End Sub

#End Region

#Region "自定义下载"

    Private Sub SaveCacheDownloadFolder() Handles TextDownloadFolder.ValidatedTextChanged
        Settings.Set("CacheDownloadFolder", TextDownloadFolder.Text)
        CType(TextDownloadName.ValidateRules.First, ValidateFileName).ParentFolder = TextDownloadFolder.Text
        TextDownloadName.Validate()
    End Sub

    Private Sub StartButtonRefresh()
        BtnDownloadStart.IsEnabled = TextDownloadFolder.IsValidated AndAlso TextDownloadUrl.IsValidated AndAlso TextDownloadName.IsValidated
        BtnDownloadOpen.IsEnabled = TextDownloadFolder.IsValidated
    End Sub

    Private Sub TextDownloadValidatedTextChanged() Handles TextDownloadUrl.ValidatedTextChanged, TextDownloadFolder.ValidatedTextChanged, TextDownloadName.ValidatedTextChanged
        StartButtonRefresh()
    End Sub

    Private Sub TextDownloadGlobalValidateChanged(sender As Object, e As EventArgs) Handles TextDownloadUrl.ValidateChanged, TextDownloadFolder.ValidateChanged, TextDownloadName.ValidateChanged
        If BtnDownloadStart Is Nothing OrElse BtnDownloadOpen Is Nothing Then Return
        If sender Is TextDownloadUrl OrElse sender Is TextDownloadFolder OrElse sender Is TextDownloadName Then StartButtonRefresh()
    End Sub

    Private Sub TextDownloadUrl_KeyDown(sender As Object, e As KeyEventArgs) Handles TextDownloadUrl.KeyDown, TextDownloadFolder.KeyDown, TextDownloadName.KeyDown
        If e.Key = Key.Enter AndAlso BtnDownloadStart.IsEnabled Then BtnDownloadStart_Click()
    End Sub

    Private Sub TextDownloadUrl_TextChanged() Handles TextDownloadUrl.ValidatedTextChanged
        Try
            If TextDownloadName.Text = "" AndAlso TextDownloadUrl.Text <> "" Then
                TextDownloadName.Text = PathUtils.GetLastPart(WebUtility.UrlDecode(TextDownloadUrl.Text))
            End If
        Catch
        End Try
    End Sub

    Private Sub BtnDownloadStart_Click() Handles BtnDownloadStart.Click
        StartCustomDownload(TextDownloadUrl.Text, TextDownloadName.Text, TextDownloadFolder.Text)
        TextDownloadUrl.Text = ""
        TextDownloadUrl.Validate()
        TextDownloadUrl.ForceShowAsSuccess()
        TextDownloadName.Text = ""
        TextDownloadName.Validate()
        TextDownloadName.ForceShowAsSuccess()
        StartButtonRefresh()
    End Sub

    Public Shared Sub StartCustomDownload(Url As String, FileName As String, Optional Folder As String = Nothing)
        Try
            If String.IsNullOrWhiteSpace(Folder) Then
                Folder = Dialogs.SaveFile("选择文件保存位置", FileName, filter:=Nothing)
                If Folder Is Nothing Then Return
                If Folder.EndsWithF(FileName) Then Folder = Folder.Substring(0, Folder.Length - FileName.Length)
            End If
            Folder = Folder.Replace("/", "\").TrimEnd("\"c) & "\"

            Try
                DirectoryUtils.Create(Folder)
                CheckPermissionWithException(Folder)
            Catch ex As Exception
                Logger.Error(ex, $"访问文件夹失败（{Folder}）", LogBehavior.Toast)
                Return
            End Try

            Logger.Info($"自定义下载文件名：{FileName}")
            Logger.Info($"自定义下载文件目标：{Folder}")
            Dim Uuid = GetUuid()
            Dim DownloadLoader As New LoaderDownload("自定义下载文件：" & FileName & " ", New List(Of NetFile) From {
                New NetFile({Url}, Folder & FileName, Nothing, SimulateBrowserHeaders:=True)
            })
            Dim Loader As New LoaderCombo(Of Integer)("自定义下载 (" & Uuid & ") ", {DownloadLoader})
            Loader.OnStateChanged = AddressOf DownloadState
            Loader.Start()
            LoaderTaskbarAdd(Loader)
            FrmMain.BtnExtraDownload.ShowRefresh()
            FrmMain.BtnExtraDownload.Ribble()
        Catch ex As Exception
            Logger.Error(ex, "开始自定义下载失败", LogBehavior.AlertThenFeedback)
        End Try
    End Sub

    Private Sub BtnDownloadSelect_Click() Handles BtnDownloadSelect.Click
        Dim Folder = Dialogs.SelectFolder("选择目标文件夹", False).FirstOrDefault
        If Folder IsNot Nothing Then TextDownloadFolder.Text = Folder
    End Sub

    Private Sub BtnDownloadOpen_Click() Handles BtnDownloadOpen.Click
        Try
            DirectoryUtils.Create(TextDownloadFolder.Text)
            StartProcess(TextDownloadFolder.Text)
        Catch ex As Exception
            Logger.Warn(ex, "打开下载文件夹失败")
        End Try
    End Sub

    Private Shared Sub DownloadState(Loader As LoaderBase)
        Dim DownloadLoader = CType(Loader, LoaderCombo(Of Integer))
        Select Case DownloadLoader.State
            Case LoadState.Finished
                Hint(DownloadLoader.Name & "完成！", HintType.Green)
                Beep()
            Case LoadState.Failed
                Logger.Error(DownloadLoader.Error, $"{DownloadLoader.Name}失败", LogBehavior.Alert)
                Beep()
            Case LoadState.Canceled
                Hint(DownloadLoader.Name & "已取消！")
        End Select
    End Sub

#End Region


#Region "垃圾清理"

    Private Sub BtnClear_Click() Handles BtnClear.Click
        RubbishClear()
    End Sub

    Public Shared Sub RubbishClear()
        RunInUi(Sub()
                    If FrmOtherTest IsNot Nothing AndAlso FrmOtherTest.BtnClear IsNot Nothing AndAlso FrmOtherTest.BtnClear.IsLoaded Then
                        FrmOtherTest.BtnClear.IsEnabled = False
                    End If
                End Sub)
        RunInNewThread(Sub()
                           Try
                               If ModWatcher.HasRunningMinecraft OrElse McLaunchLoader.State = LoadState.Loading Then
                                   Hint("请先关闭所有运行中的游戏！")
                                   Return
                               End If
                               If HasDownloadingTask() Then
                                   Hint("请在所有下载任务完成后再来清理吧！")
                                   Return
                               End If

                               If Not McFolderList.Any Then McFolderListLoader.Start()
                               If PathTemp = Path.GetTempPath() & "PCL\" Then
                                   If Settings.Get(Of Integer)("HintClearRubbish") <= 2 Then
                                       If MyMsgBox("即将清理游戏日志、错误报告、缓存等文件。" & vbCrLf &
                                                   "虽然应该没人往这些地方放重要文件，但还是问一下，是否确认继续？" & vbCrLf & vbCrLf &
                                                   "在完成清理后，需要重启 PCL。", "清理确认", "确定", "取消") = 2 Then Return
                                       Settings.Set("HintClearRubbish", Settings.Get(Of Integer)("HintClearRubbish") + 1)
                                   End If
                               Else
                                   If MyMsgBox("即将清理游戏日志、错误报告、缓存等文件。" & vbCrLf & vbCrLf &
                                               "你已将缓存文件夹手动修改为：" & PathTemp & vbCrLf &
                                               "清理过程中，将删除该文件夹中的所有内容，且无法恢复。请确认其中没有除了 PCL 缓存以外的重要文件！" & vbCrLf & vbCrLf &
                                               "在完成清理后，需要重启 PCL。", "清理确认", "确定", "取消") = 2 Then Return
                               End If

                               Dim Folders As New List(Of String)
                               If Not McFolderList.Any Then McFolderListLoader.WaitForExit()
                               For Each Folder In McFolderList
                                   Folders.Add(Folder.Location)
                                   Folders.AddRange(DirectoryUtils.EnumerateDirectories(Folder.Location & "versions", False, "*"))
                               Next

                               Dim DeleteFolder As Action(Of String) =
                                   Sub(DirPath As String)
                                       Try
                                           DirectoryUtils.Delete(DirPath, False)
                                       Catch ex As Exception
                                           Logger.Warn(ex, $"清理文件夹失败（{DirPath}）")
                                       End Try
                                   End Sub

                               For Each Folder In Folders
                                   DeleteFolder(Path.Combine(Folder, ".mixin.out"))
                                   DeleteFolder(Path.Combine(Folder, "crash-reports"))
                                   DeleteFolder(Path.Combine(Folder, "debug"))
                                   DeleteFolder(Path.Combine(Folder, "logs"))
                                   For Each File In DirectoryUtils.EnumerateFiles(Folder, False, "*")
                                       Dim Name = PathUtils.GetLastPart(File)
                                       If Name.StartsWithF("hs_err_pid") OrElse Name.EndsWithF(".log") OrElse Name = "WailaErrorOutput.txt" Then
                                           FileUtils.Delete(File, False)
                                       End If
                                   Next
                                   For Each SubFolder In DirectoryUtils.EnumerateDirectories(Folder, False, "*")
                                       Dim Name = PathUtils.GetLastPart(SubFolder)
                                       If Name = PathUtils.GetLastPart(Folder) & "-natives" OrElse Name = "natives-windows-x86_64" Then
                                           DeleteFolder(SubFolder)
                                       End If
                                   Next
                               Next

                               DeleteFolder(PathTemp)
                               DeleteFolder(OsDrive & "ProgramData\PCL\")
                               MyMsgBox("垃圾已经清理好啦！", "清理完成", "重启 PCL", HighLight:=True, ForceWait:=True)
                               StartProcess(New ProcessStartInfo(PathExe, "--wait"))
                               FormMain.EndProgramForce()
                           Catch ex As Exception
                               Logger.Error(ex, "清理垃圾失败", LogBehavior.Toast)
                           Finally
                               RunInUiWait(Sub()
                                               If FrmOtherTest IsNot Nothing AndAlso FrmOtherTest.BtnClear IsNot Nothing AndAlso FrmOtherTest.BtnClear.IsLoaded Then
                                                   FrmOtherTest.BtnClear.IsEnabled = True
                                               End If
                                           End Sub)
                           End Try
                       End Sub, "Rubbish Clear")
    End Sub

#End Region

#Region "内存优化"

    Private Sub BtnMemory_Click() Handles BtnMemory.Click
        RunInThread(Sub() MemoryOptimize(True))
    End Sub

    Public Shared Sub MemoryOptimize(ShowHint As Boolean)
        If IsMemoryOptimizeRunning Then
            If ShowHint Then Hint("内存优化尚未结束，请稍等！")
            Return
        End If
        IsMemoryOptimizeRunning = True

        Dim Value As Long
        If WindowsUtils.HasAdminRole() Then
            Dim OldMemory = CDec(My.Computer.Info.AvailablePhysicalMemory)
            Try
                MemoryOptimizeInternal(ShowHint)
            Catch ex As Exception
                Logger.Warn(ex, "内存优化失败", If(ShowHint, LogBehavior.Toast, LogBehavior.ToastIfDebug))
                Return
            Finally
                IsMemoryOptimizeRunning = False
            End Try
            Value = CLng(CDec(My.Computer.Info.AvailablePhysicalMemory) - OldMemory)
        Else
            Logger.Info("没有管理员权限，将以命令行方式进行内存优化")
            Try
                Value = CLng(RunAsAdmin("--memory")) * 1024L
            Catch ex As Exception
                Logger.Warn(ex, "命令行形式内存优化失败")
                If ShowHint Then Hint($"获取管理员权限失败，请尝试右键 PCL，选择 {vbLQ}以管理员身份运行{vbRQ}！", HintType.Red)
                Return
            Finally
                IsMemoryOptimizeRunning = False
            End Try
            If Value < 0 Then Return
        End If

        Dim LeftMemory = StringUtils.FormatByteSize(CLng(My.Computer.Info.AvailablePhysicalMemory))
        Logger.Info($"内存优化完成，可用内存改变量：{StringUtils.FormatByteSize(Value)}，大致剩余内存：{LeftMemory}")
        If Value > 0 Then
            If ShowHint Then Hint($"内存优化完成，可用内存增加了 {StringUtils.FormatByteSize(CLng(Math.Round(Value * 0.8)))}，目前剩余内存 {LeftMemory}！", HintType.Green)
        ElseIf ShowHint Then
            Hint($"内存优化完成，已经优化到了最佳状态，目前剩余内存 {LeftMemory}！")
        End If
    End Sub

    Public Shared Sub MemoryOptimizeInternal(ShowHint As Boolean)
        If Not WindowsUtils.HasAdminRole() Then
            Throw New Exception("内存优化功能需要管理员权限！" & vbCrLf &
                                "如果需要自动以管理员身份启动 PCL，可以右键 PCL，打开 属性 → 兼容性 → 以管理员身份运行此程序。")
        End If

        Logger.Info("获取内存优化权限")
        Using Identity = WindowsIdentity.GetCurrent(TokenAccessLevels.Query Or TokenAccessLevels.AdjustPrivileges)
            Dim NewState As New PrivilegeToken With {.PrivilegeCount = 1, .Luid = 0, .Attributes = 2}
            Dim SystemName As String = Nothing
            Dim PrivilegeName As String = "SeProfileSingleProcessPrivilege"
            Dim PreviousState = IntPtr.Zero
            Dim ReturnLength = 0
            If Not LookupPrivilegeValue(SystemName, PrivilegeName, NewState.Luid) OrElse
               Not AdjustTokenPrivileges(Identity.Token, False, NewState, Marshal.SizeOf(NewState), PreviousState, ReturnLength) OrElse
               Marshal.GetLastWin32Error() <> 0 Then
                Throw New Exception($"获取内存优化权限失败（错误代码：{Marshal.GetLastWin32Error()}）")
            End If
        End Using

        If ShowHint Then Hint("正在进行内存优化……")
        For TypeId = 2 To 4
            Logger.Info($"内存优化操作 {TypeId} 开始")
            Dim Handle = GCHandle.Alloc(TypeId, GCHandleType.Pinned)
            Dim Result = CInt(NtSetSystemInformation(80, Handle.AddrOfPinnedObject(), Marshal.SizeOf(TypeId)))
            Handle.Free()
            If Result <> 0 Then Throw New Exception($"内存优化操作 {TypeId} 失败（错误代码：{Result}）")
        Next
    End Sub

    <DllImport("ntdll.dll", CharSet:=CharSet.Ansi, ExactSpelling:=True, SetLastError:=True)>
    Private Shared Function NtSetSystemInformation(SystemInformationClass As Integer, SystemInformation As IntPtr, SystemInformationLength As Integer) As UInteger
    End Function

    <DllImport("advapi32.dll", CharSet:=CharSet.Ansi, EntryPoint:="LookupPrivilegeValueA", ExactSpelling:=True, SetLastError:=True)>
    Private Shared Function LookupPrivilegeValue(<MarshalAs(UnmanagedType.VBByRefStr)> ByRef lpSystemName As String,
                                                 <MarshalAs(UnmanagedType.VBByRefStr)> ByRef lpName As String,
                                                 ByRef lpLuid As Long) As Boolean
    End Function

    <DllImport("advapi32.dll", CharSet:=CharSet.Ansi, ExactSpelling:=True, SetLastError:=True)>
    Private Shared Function AdjustTokenPrivileges(TokenHandle As IntPtr, DisableAllPrivileges As Boolean,
                                                  ByRef NewState As PrivilegeToken, BufferLength As Integer,
                                                  ByRef PreviousState As IntPtr, ByRef ReturnLength As Integer) As Boolean
    End Function

#End Region


End Class
